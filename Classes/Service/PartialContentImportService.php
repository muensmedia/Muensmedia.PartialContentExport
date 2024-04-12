<?php
namespace Muensmedia\PartialContentExport\Service;

use Neos\ContentRepository\Domain\NodeAggregate\NodeName;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Exception\ImportException;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeExistsException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Package\Exception\InvalidPackageStateException;
use Neos\Flow\Package\Exception\UnknownPackageException;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageVariant;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\SiteService;
use Neos\Neos\EventLog\Domain\Service\EventEmittingService;
use Neos\Neos\Domain\Exception as NeosException;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\ImportExport\NodeImportService;
use Neos\ContentRepository\Domain\Utility\NodePaths;

/**
 * The Site Import Service
 *
 * @Flow\Scope("singleton")
 * @api
 */
class PartialContentImportService
{
    /**
     * @Flow\Inject
     * @var PackageManager
     */
    protected PackageManager $packageManager;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected SiteRepository $siteRepository;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected ContextFactoryInterface $contextFactory;

    /**
     * @Flow\Inject
     * @var NodeImportService
     */
    protected NodeImportService $nodeImportService;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected WorkspaceRepository $workspaceRepository;

    /**
     * @Flow\Inject
     * @var ReflectionService
     */
    protected ReflectionService $reflectionService;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected ObjectManagerInterface $objectManager;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected PersistenceManagerInterface $persistenceManager;

    /**
     * @Flow\Inject
     * @var EventEmittingService
     */
    protected EventEmittingService $eventEmittingService;

    /**
     * @var string
     */
    protected ?string $resourcesPath = null;

    /**
     * An array that contains all fully qualified class names that extend ImageVariant including ImageVariant itself
     *
     * @var array<string>
     */
    protected array $imageVariantClassNames = [];

    /**
     * An array that contains all fully qualified class names that implement AssetInterface
     *
     * @var array<string>
     */
    protected array $assetClassNames = [];

    /**
     * An array that contains all fully qualified class names that extend \DateTime including \DateTime itself
     *
     * @var array<string>
     */
    protected array $dateTimeClassNames = [];

    /**
     * @return void
     */
    public function initializeObject()
    {
        $this->imageVariantClassNames = $this->reflectionService->getAllSubClassNamesForClass(ImageVariant::class);
        array_unshift($this->imageVariantClassNames, ImageVariant::class);

        $this->assetClassNames = $this->reflectionService->getAllImplementationClassNamesForInterface(AssetInterface::class);

        $this->dateTimeClassNames = $this->reflectionService->getAllSubClassNamesForClass('DateTime');
        array_unshift($this->dateTimeClassNames, 'DateTime');
    }


    /**
     * @throws NeosException
     */
    public function resolveImportFile(?string $packageKey, string $pathOrFileName ): string {
        if ($packageKey === null) {
            $fullPath = $pathOrFileName;
            if (!file_exists($fullPath))
                throw new NeosException(sprintf('File not found: "%s".', $fullPath), 1711116833);

        } else {
            if (!$this->packageManager->isPackageAvailable($packageKey))
                throw new NeosException(sprintf('Error: Package "%s" is not active.', $packageKey), 1384192950);

            $fullPath = sprintf('resource://%s/Private/Content/%s', $packageKey, $pathOrFileName);
            if (!file_exists($fullPath))
                throw new NeosException(sprintf('Error: No content found in package "%s".', $packageKey), 1384192955);

        }

        return $fullPath;
    }

    public function findPartialImportRoot( \XMLReader $xml ): void {
        while ($xml->read())
            if ($xml->nodeType === \XMLReader::ELEMENT && $xml->name === 'partial')
                break;
    }

    /**
     * @throws NeosException
     */
    protected function getXMLProperty(\XMLReader $xml, string $property ): string {
        $value = $xml->getAttribute($property);
        if ($value === null)
            throw new NeosException(sprintf('Error: Unable to read property "%s".', $property), 1711117563);
        return $value;
    }

    public function getImportSiteNodeName( \XMLReader $xml ): string {
        return $this->getXMLProperty( $xml, 'siteNodeName' );
    }

    public function getImportNodeName( \XMLReader $xml ): string {
        return $this->getXMLProperty( $xml, 'sourceName' );
    }

    public function getImportNodeType( \XMLReader $xml ): string {
        return $this->getXMLProperty( $xml, 'sourceNodeType' );
    }

    public function getImportNodeID( \XMLReader $xml ): string {
        return $this->getXMLProperty( $xml, 'sourceIdentifier' );
    }

    public function getFullImportPath( \XMLReader $xml, ?string $overridePath = null ): string {

        $path = $overridePath;// ?? $this->getXMLProperty( $xml, 'sourcePath' );
        if (!$path)
            $path = implode(
                '/',
                array_slice( explode('/', $this->getXMLProperty( $xml, 'sourcePath' )), 0, -1)
            );

        $prefix = SiteService::SITES_ROOT_PATH . '/';
        return str_starts_with( $path, $prefix ) ? $path : "{$prefix}{$path}";
    }

    /**
     * Imports one or multiple sites from the XML file given
     *
     * @param \XMLReader $xmlReader
     * @param string $directory
     * @param string $targetPath
     * @return Site The imported site
     * @throws IllegalObjectTypeException
     * @throws ImportException
     * @throws NeosException
     */
    public function importFromXML(\XMLReader $xmlReader, string $directory, string $targetPath): Site
    {
        /** @var Site $importedSite */
        $site = null;

        if ($this->workspaceRepository->findOneByName('live') === null) {
            $this->workspaceRepository->add(new Workspace('live'));
            $this->persistenceManager->persistAll();
        }

        $site = $this->siteRepository->findOneByNodeName( $this->getImportSiteNodeName( $xmlReader ) );
        if ($site === null) throw new NeosException(sprintf('The given site does not exist. A partial import can only target an existing site.'), 1708095484);

        $siteResourcesPackageKey = $this->getXMLProperty( $xmlReader, 'siteResourcesPackageKey');
        if ($site->getSiteResourcesPackageKey() !== $siteResourcesPackageKey)
            throw new NeosException(sprintf('The local site\'s resources package key "%s" does not match the one defined in the partial export ("%s"). Cannot continue.', $site->getSiteResourcesPackageKey(), $siteResourcesPackageKey), 1708095586);

        $targetPath ??= $xmlReader->getAttribute('sourcePath');
        if ($targetPath === null)
            throw new NeosException(sprintf('No target path given.'), 1708096178);

        $rootNode = $this->contextFactory->create()->getRootNode();

        // We fetch the workspace to be sure it's known to the persistence manager and persist all
        // so the workspace and site node are persisted before we import any nodes to it.
        $rootNode->getContext()->getWorkspace();
        $this->persistenceManager->persistAll();

        $this->nodeImportService->import($xmlReader, $targetPath, $directory . '/Resources');

        if ($site === null) {
            throw new NeosException(sprintf('The XML file did not contain a valid site node.'), 1418999522);
        }
        $this->emitSiteImported($site);
        return $site;
    }

    /**
     * Signal that is triggered when a site has been imported successfully
     *
     * @Flow\Signal
     * @param Site $site The site that has been imported
     * @return void
     */
    protected function emitSiteImported(Site $site)
    {
    }
}
