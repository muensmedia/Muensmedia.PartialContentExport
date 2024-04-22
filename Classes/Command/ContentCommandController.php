<?php
namespace Muensmedia\PartialContentExport\Command;

use Muensmedia\PartialContentExport\Service\NodePathNormalizerService;
use Muensmedia\PartialContentExport\Service\PartialContentExportService;
use Muensmedia\PartialContentExport\Service\PartialContentImportService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
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

    public function importCommand(?string $packageKey = null, ?string $filename = null, ?string $targetPath = null): void
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
    public function exportCommand(string $source, string $siteName = null, bool $tidy = true, string $filename = null, string $packageKey = null, string $nodeTypeFilter = null): void
    {
        if (!$this->confirmPath( "We're about to export the marked node and all it's sub-nodes.", $siteName, $source))
            return;

        $node = $this->nodePathNormalizer->getNodeFromPathOrIdentifier( $siteName, $source );
        if (!$node)
            throw new NeosException('Error: The given source node could not be found.', 1710521291);
        $sourcePath = $node->findNodePath();

        if ($packageKey !== null) {

            $this->outputLine('Exporting the content at "%s" to package "%s"...', [$sourcePath, $packageKey]);
            $this->exportService->exportToPackage($sourcePath, $packageKey, $tidy, $nodeTypeFilter, $filename );
            $this->outputLine('Partial export of "%s" to package "%s" completed.', [$sourcePath, $packageKey]);

        } elseif ($filename !== null) {

            $this->outputLine('Exporting the content at "%s" to file "%s"...', [$sourcePath, $filename]);
            $this->exportService->exportToFile($sourcePath, $filename, $tidy, $nodeTypeFilter );
            $this->outputLine('Partial export of "%s" to file "%s" completed.', [$sourcePath, $filename]);

        } else {
            $this->output($this->exportService->exportToString($sourcePath, $tidy, $nodeTypeFilter));
        }
    }


}
