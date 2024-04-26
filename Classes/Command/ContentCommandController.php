<?php
namespace Muensmedia\PartialContentExport\Command;

use Muensmedia\PartialContentExport\Service\NodePathNormalizerService;
use Muensmedia\PartialContentExport\Service\PartialContentExportService;
use Muensmedia\PartialContentExport\Service\PartialContentImportService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Composer\ComposerUtility;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Exception as NeosException;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\SiteImportService;
use Neos\Neos\Domain\Service\SiteService;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Service\NodeService;
use Neos\Utility\Exception\FilesException;
use Psr\Log\LoggerInterface;

/**
 * The Site Command Controller
 *
 * @Flow\Scope("singleton")
 */
class ContentCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var PartialContentImportService
     */
    protected $importService;

    /**
     * @Flow\Inject
     * @var PartialContentExportService
     */
    protected $exportService;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var SiteService
     */
    protected $siteService;

    /**
     * @Flow\Inject
     * @var PackageManager
     */
    protected $packageManager;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $nodeContextFactory;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var NodeService
     */
    protected $nodeService;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @var ThrowableStorageInterface|null
     */
    private ?ThrowableStorageInterface $throwableStorage;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @Flow\Inject
     * @var NodePathNormalizerService
     */
    protected $nodePathNormalizer;

    const Supported_Extensions = [
        'sitegeist/taxonomy'
    ];

    /**
     * @param ThrowableStorageInterface $throwableStorage
     */
    public function injectThrowableStorage(ThrowableStorageInterface $throwableStorage): void
    {
        $this->throwableStorage = $throwableStorage;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function injectLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    protected function confirmPath( string $query, string $siteName, string $source, string $marker = '-> ', ?string $pseudoElement = null ): bool {

        $this->outputLine( $query );

        $nodes = $this->nodePathNormalizer->allNodesOnPath( $siteName, $source );
        for ($i = 0; $i < count( $nodes ); $i++)  {
            $name = $nodes[$i]->getProperty('title') ?? $nodes[$i]->getProperty('name') ?? $nodes[$i]->getNodeName();
            $type = $nodes[$i]->getNodeType()->getName();

            $this->outputLine( '| ' . str_repeat('  ', $i) . ( (!$pseudoElement && $i === count($nodes)-1) ? $marker : '') . "$name ($type)" );
        }

        if ($pseudoElement)
            $this->outputLine( '| ' . str_repeat('  ', $i) . $marker . $pseudoElement );

        return $this->output->askConfirmation('Continue (y/n)?', false );
    }

    protected function confirmExtension( array &$extensions, string $extension, string $description ): void {
        if (in_array($extension, $extensions) || empty($version = ComposerUtility::getPackageVersion($extension))) return;

        $this->outputLine('--');
        $this->outputLine( "You have <b>$extension</b> installed in version $version." );
        $this->outputLine( $description );
        if ($this->output->askConfirmation('Include this in the export (y/n)?', false ))
            $extensions[] = $extension;
        $this->outputLine('--');
    }

    public function importCommand(
        ?string $packageKey = null,
        ?string $filename = null,
        ?string $targetPath = null
    ): void
    {
        if ($packageKey === null && $filename === null) {
            $this->outputLine('You have to specify either "--package-key" or "--filename"');
            $this->quit(1);
        }

        if (!str_ends_with( $filename, '.xml' )) $filename .= '.xml';
        $xmlPath = $this->importService->resolveImportFile( $packageKey, $filename );
        $xmlReader = new \XMLReader();
        if ($xmlReader->open($xmlPath, null, LIBXML_PARSEHUGE) === false) {
            throw new NeosException(sprintf('Error: XMLReader could not open "%s".', $xmlPath), 1710510972);
        }

        $this->importService->findPartialImportRoot( $xmlReader );

        $siteNodeName = $this->importService->getImportSiteNodeName( $xmlReader );
        $targetPath = $this->importService->getFullImportPath( $xmlReader, $targetPath );
        $nodeId = $this->importService->getImportNodeID( $xmlReader );

        $existingNode = $this->nodePathNormalizer->getNodeFromPathOrIdentifier( $siteNodeName, $nodeId );
        if ($existingNode) {
            $existingPath  = $existingNode->findNodePath()->jsonSerialize();
            $existingParentPath  = $existingNode->findParentNode()->findNodePath()->jsonSerialize();
            if ($targetPath !== $existingPath && $targetPath !== $existingParentPath)
                throw new NeosException(sprintf('Error: You are trying to import to %s a node that already exists in your site, but at a different path (%s). This command cannot be used to move a node to a different place.', $targetPath, $existingPath), 1711120231);

            $targetPath = $existingParentPath;

            if (!$this->confirmPath( "The following node will be updated.", $siteNodeName, $existingPath, '[MERGE] -> ' ))
                return;
        } else {

            $nodeName = $this->importService->getImportNodeName( $xmlReader );
            $nodeType = $this->importService->getImportNodeType( $xmlReader );
            if (!$this->confirmPath("The new content will be inserted as follows.", $siteNodeName, $targetPath, '[NEW] -> ', "$nodeName ($nodeType)"))
                return;
        }

        while ( $this->importService->findNextExtensionNode( $xmlReader ) ) {
            $package = $this->importService->getXMLProperty( $xmlReader, 'package' );
            if (!in_array( $package, self::Supported_Extensions ))
                throw new NeosException(sprintf('Error: The export contains data for package "%s" which is not supported by this importer.', $package), 1714144525);

            if (empty($version = ComposerUtility::getPackageVersion( $package )))
                throw new NeosException(sprintf('Error: Data for package "%s" is included in the export, but the package is not installed.', $package), 1714143331);

            $containedVersion = $this->importService->getXMLProperty( $xmlReader, 'version' );
            if ($version !== $containedVersion) {
                $this->outputLine('--');
                $this->outputLine( "<b>Warning: </b> The export contains data for <b>{$package}</b>, but the versions do not match." );
                $this->outputLine("Locally installed: <b>{$version}</b>");
                $this->outputLine("Exported from: <b>{$containedVersion}</b>");
                if (!$this->output->askConfirmation('Importing data from a different package version may cause problems. Continue (y/n)?', false ))
                    return;
                $this->outputLine('--');
            }
        }

        $xmlReader = new \XMLReader();
        if ($xmlReader->open($xmlPath, null, LIBXML_PARSEHUGE) === false) {
            throw new NeosException(sprintf('Error: XMLReader could not open "%s".', $xmlPath), 1710510972);
        }

        $this->importService->findPartialImportRoot( $xmlReader );

        // Since this command uses a lot of memory when large sites are imported, we warn the user to watch for
        // the confirmation of a successful import.
        $this->outputLine('<b>This command can use a lot of memory when importing sites with many resources.</b>');
        $this->outputLine('If the import is successful, you will see a message saying "Import of site ... finished".');
        $this->outputLine('If you do not see this message, the import failed, most likely due to insufficient memory.');
        $this->outputLine('Increase the <b>memory_limit</b> configuration parameter of your php CLI to attempt to fix this.');
        $this->outputLine('Starting import...');
        $this->outputLine('---');


        $site = null;
        try {
            $site = $this->importService->importFromXML($xmlReader, dirname( $xmlPath ), $targetPath);
        } catch (\Exception $exception) {
            $logMessage = $this->throwableStorage->logThrowable($exception);
            $this->logger->error($logMessage, LogEnvironment::fromMethodName(__METHOD__));
            $this->outputLine('<error>During the import of "%s" an exception occurred: %s, see log for further information.</error>', [$xmlPath, $exception->getMessage()]);
            $this->quit(1);
        }

        $this->outputLine('Import of "%s" finished.', [$xmlPath]);
    }

    /**
     * @throws FilesException
     * @throws NeosException
     */
    public function exportCommand(
        string $source,
        string $siteName = null,
        bool $tidy = true,
        string $filename = null,
        string $packageKey = null,
        string $nodeTypeFilter = null,
        array $extension = [],
        bool $detectExtensions = true
    ): void
    {
        if (!$this->confirmPath( "We're about to export the marked node and all it's sub-nodes.", $siteName, $source))
            return;

        foreach ($extension as $e)
            if (!in_array($e, self::Supported_Extensions))
                throw new NeosException("Error: The package $e is not supported.", 1714144368);

        if ($detectExtensions) {
            $this->confirmExtension( $extension, 'sitegeist/taxonomy',
                'You can include <b>all</b> vocabularies present in this Neos instance in this export.'
            );
        }

        $node = $this->nodePathNormalizer->getNodeFromPathOrIdentifier( $siteName, $source );
        if (!$node)
            throw new NeosException('Error: The given source node could not be found.', 1710521291);
        $sourcePath = $node->findNodePath();

        if ($packageKey !== null) {

            $this->outputLine('Exporting the content at "%s" to package "%s"...', [$sourcePath, $packageKey]);
            $this->exportService->exportToPackage($sourcePath, $packageKey, $tidy, $nodeTypeFilter, $filename, $extension );
            $this->outputLine('Partial export of "%s" to package "%s" completed.', [$sourcePath, $packageKey]);

        } elseif ($filename !== null) {

            $this->outputLine('Exporting the content at "%s" to file "%s"...', [$sourcePath, $filename]);
            $this->exportService->exportToFile($sourcePath, $filename, $tidy, $nodeTypeFilter, $extension );
            $this->outputLine('Partial export of "%s" to file "%s" completed.', [$sourcePath, $filename]);

        } else {
            $this->output($this->exportService->exportToString($sourcePath, $tidy, $nodeTypeFilter, $extension));
        }
    }


}
