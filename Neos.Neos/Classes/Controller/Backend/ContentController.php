<?php
namespace Neos\Neos\Controller\Backend;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\EelHelper\TranslationHelper;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Property\PropertyMappingConfiguration;
use Neos\Flow\Property\TypeConverter\ObjectConverter;
use Neos\Flow\Property\TypeConverter\PersistentObjectConverter;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Media\Domain\Model\ImageVariant;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\ImageRepository;
use Neos\Media\Domain\Service\ThumbnailService;
use Neos\Media\TypeConverter\AssetInterfaceConverter;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\TypeConverter\ImageInterfaceArrayPresenter;
use Neos\Neos\Controller\BackendUserTranslationTrait;
use Neos\Neos\Domain\Model\PluginViewDefinition;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Service\PluginService;
use Neos\Neos\TypeConverter\EntityToIdentityConverter;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\FlowQuery\FlowQuery;

/**
 * The TYPO3 ContentModule controller; providing backend functionality for the Content Module.
 *
 * @Flow\Scope("singleton")
 */
class ContentController extends ActionController
{
    use BackendUserTranslationTrait;
    use CreateContentContextTrait;

    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var ImageRepository
     */
    protected $imageRepository;

    /**
     * @Flow\Inject
     * @var AssetCollectionRepository
     */
    protected $assetCollectionRepository;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * The pluginService
     *
     * @var PluginService
     * @Flow\Inject
     */
    protected $pluginService;

    /**
     * @Flow\Inject
     * @var ImageInterfaceArrayPresenter
     */
    protected $imageInterfaceArrayPresenter;

    /**
     * @Flow\Inject
     * @var EntityToIdentityConverter
     */
    protected $entityToIdentityConverter;

    /**
     * @Flow\Inject
     * @var ThumbnailService
     */
    protected $thumbnailService;

    /**
     * Initialize property mapping as the upload usually comes from the Inspector JavaScript
     */
    public function initializeUploadAssetAction()
    {
        $propertyMappingConfiguration = $this->arguments->getArgument('asset')->getPropertyMappingConfiguration();
        $propertyMappingConfiguration->allowAllProperties();
        $propertyMappingConfiguration->setTypeConverterOption(PersistentObjectConverter::class, PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED, true);
        $propertyMappingConfiguration->setTypeConverterOption(AssetInterfaceConverter::class, AssetInterfaceConverter::CONFIGURATION_ONE_PER_RESOURCE, true);
        $propertyMappingConfiguration->allowCreationForSubProperty('resource');
    }

    /**
     * Upload a new image, and return its metadata.
     *
     * Depending on the $metadata argument it will return asset metadata for the AssetEditor
     * or image metadata for the ImageEditor
     *
     * @param Asset $asset
     * @param string $metadata Type of metadata to return ("Asset" or "Image")
     * @return string
     */
    public function uploadAssetAction(Asset $asset, $metadata)
    {
        $this->response->setHeader('Content-Type', 'application/json');

        /** @var Site $currentSite */
        $currentSite = $this->siteRepository->findOneByNodeName($this->request->getInternalArgument('__siteNodeName'));
        if ($currentSite !== null && $currentSite->getAssetCollection() !== null) {
            $currentSite->getAssetCollection()->addAsset($asset);
            $this->assetCollectionRepository->update($currentSite->getAssetCollection());
        }

        switch ($metadata) {
            case 'Asset':
                $result = $this->getAssetProperties($asset);
                if ($this->persistenceManager->isNewObject($asset)) {
                    $this->assetRepository->add($asset);
                }
                break;
            case 'Image':
                $result = $this->getImageInterfacePreviewData($asset);
                if ($this->persistenceManager->isNewObject($asset)) {
                    $this->imageRepository->add($asset);
                }
                break;
            default:
                $this->response->setStatus(400);
                $result = array('error' => 'Invalid "metadata" type: ' . $metadata);
        }
        return json_encode($result);
    }

    /**
     * Configure property mapping for adding a new image variant.
     *
     * @return void
     */
    public function initializeCreateImageVariantAction()
    {
        $this->arguments->getArgument('asset')->getPropertyMappingConfiguration()
            ->allowOverrideTargetType()
            ->allowAllProperties()
            ->setTypeConverterOption(PersistentObjectConverter::class, PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED, true);
    }

    /**
     * Generate a new image variant from given data.
     *
     * @param ImageVariant $asset
     * @return string
     */
    public function createImageVariantAction(ImageVariant $asset)
    {
        if ($this->persistenceManager->isNewObject($asset)) {
            $this->assetRepository->add($asset);
        }

        $propertyMappingConfiguration = new PropertyMappingConfiguration();
        // This will not be sent as "application/json" as we need the JSON string and not the single variables.
        return json_encode($this->entityToIdentityConverter->convertFrom($asset, 'array', [], $propertyMappingConfiguration));
    }

    /**
     * Fetch the metadata for a given image
     *
     * @param ImageInterface $image
     *
     * @return string JSON encoded response
     */
    public function imageWithMetadataAction(ImageInterface $image)
    {
        $this->response->setHeader('Content-Type', 'application/json');
        $imageProperties = $this->getImageInterfacePreviewData($image);

        return json_encode($imageProperties);
    }

    /**
     * Returns important meta data for the given object implementing ImageInterface.
     *
     * Will return an array with the following keys:
     *
     *   "originalImageResourceUri": Uri for the original resource
     *   "previewImageResourceUri": Uri for a preview image with reduced size
     *   "originalDimensions": Dimensions for the original image (width, height, aspectRatio)
     *   "previewDimensions": Dimensions for the preview image (width, height)
     *   "object": object properties like the __identity and __type of the object
     *
     * @param ImageInterface $image The image to retrieve meta data for
     * @return array
     */
    protected function getImageInterfacePreviewData(ImageInterface $image)
    {
        // TODO: Now that we try to support all ImageInterface implementations we should use a strategy here to get the image properties for custom implementations
        if ($image instanceof ImageVariant) {
            $imageProperties = $this->getImageVariantPreviewData($image);
        } else {
            $imageProperties = $this->getImagePreviewData($image);
        }

        $imageProperties['object'] = $this->imageInterfaceArrayPresenter->convertFrom($image, 'string');
        return $imageProperties;
    }

    /**
     * @param Image $image
     * @return array
     */
    protected function getImagePreviewData(Image $image)
    {
        $imageProperties = [
            'originalImageResourceUri' => $this->resourceManager->getPublicPersistentResourceUri($image->getResource()),
            'originalDimensions' => [
                'width' => $image->getWidth(),
                'height' => $image->getHeight(),
                'aspectRatio' => $image->getAspectRatio()
            ],
            'mediaType' => $image->getResource()->getMediaType()
        ];
        $thumbnail = $this->thumbnailService->getThumbnail($image, $this->thumbnailService->getThumbnailConfigurationForPreset('Neos.Media.Browser:Preview'));
        if ($thumbnail !== null) {
            $imageProperties['previewImageResourceUri'] = $this->thumbnailService->getUriForThumbnail($thumbnail);
            $imageProperties['previewDimensions'] = [
                'width' => $thumbnail->getWidth(),
                'height' => $thumbnail->getHeight()
            ];
        }
        return $imageProperties;
    }

    /**
     * @param ImageVariant $imageVariant
     * @return array
     */
    protected function getImageVariantPreviewData(ImageVariant $imageVariant)
    {
        $image = $imageVariant->getOriginalAsset();
        $imageProperties = $this->getImagePreviewData($image);
        return $imageProperties;
    }

    /**
     * @return void
     */
    protected function initializeAssetsWithMetadataAction()
    {
        $propertyMappingConfiguration = $this->arguments->getArgument('assets')->getPropertyMappingConfiguration();
        $propertyMappingConfiguration->allowAllProperties();
        $propertyMappingConfiguration->setTypeConverterOption(AssetInterfaceConverter::class, AssetInterfaceConverter::CONFIGURATION_OVERRIDE_TARGET_TYPE_ALLOWED, true);
        $propertyMappingConfiguration->forProperty('*')->setTypeConverterOption(AssetInterfaceConverter::class, AssetInterfaceConverter::CONFIGURATION_OVERRIDE_TARGET_TYPE_ALLOWED, true);
    }

    /**
     * Fetch the metadata for multiple assets
     *
     * @param array<Neos\Media\Domain\Model\AssetInterface> $assets
     * @return string JSON encoded response
     */
    public function assetsWithMetadataAction(array $assets)
    {
        $this->response->setHeader('Content-Type', 'application/json');

        $result = array();
        foreach ($assets as $asset) {
            $result[] = $this->getAssetProperties($asset);
        }
        return json_encode($result);
    }

    /**
     * @param Asset $asset
     * @return array
     */
    protected function getAssetProperties(Asset $asset)
    {
        $assetProperties = [
            'assetUuid' => $this->persistenceManager->getIdentifierByObject($asset),
            'filename' => $asset->getResource()->getFilename()
        ];
        $thumbnail = $this->thumbnailService->getThumbnail($asset, $this->thumbnailService->getThumbnailConfigurationForPreset('Neos.Media.Browser:Thumbnail'));
        if ($thumbnail !== null) {
            $assetProperties['previewImageResourceUri'] = $this->thumbnailService->getUriForThumbnail($thumbnail);
            $assetProperties['previewSize'] = ['w' => $thumbnail->getWidth(), 'h' => $thumbnail->getHeight()];
        }

        return $assetProperties;
    }

    /**
     * Fetch the configured views for the given master plugin
     *
     * @param string $identifier Specifies the node to look up
     * @param string $workspaceName Name of the workspace to use for querying the node
     * @param array $dimensions Optional list of dimensions and their values which should be used for querying the specified node
     * @return string
     */
    public function pluginViewsAction($identifier = null, $workspaceName = 'live', array $dimensions = array())
    {
        $this->response->setHeader('Content-Type', 'application/json');

        $contentContext = $this->createContentContext($workspaceName, $dimensions);
        /** @var $node NodeInterface */
        $node = $contentContext->getNodeByIdentifier($identifier);

        $views = array();
        if ($node !== null) {
            /** @var $pluginViewDefinition PluginViewDefinition */
            $pluginViewDefinitions = $this->pluginService->getPluginViewDefinitionsByPluginNodeType($node->getNodeType());
            foreach ($pluginViewDefinitions as $pluginViewDefinition) {
                $label = $pluginViewDefinition->getLabel();

                $views[$pluginViewDefinition->getName()] = array('label' => $label);

                $pluginViewNode = $this->pluginService->getPluginViewNodeByMasterPlugin($node, $pluginViewDefinition->getName());
                if ($pluginViewNode === null) {
                    continue;
                }
                $q = new FlowQuery(array($pluginViewNode));
                $page = $q->closest('[instanceof Neos.Neos:Document]')->get(0);
                $uri = $this->uriBuilder
                    ->reset()
                    ->uriFor('show', array('node' => $page), 'Frontend\Node', 'Neos.Neos');
                $views[$pluginViewDefinition->getName()] = array(
                    'label' => $label,
                    'pageNode' => array(
                        'title' => $page->getLabel(),
                        'uri' => $uri
                    )
                );
            }
        }
        return json_encode((object) $views);
    }

    /**
     * Fetch all master plugins that are available in the current
     * workspace.
     *
     * @param string $workspaceName Name of the workspace to use for querying the node
     * @param array $dimensions Optional list of dimensions and their values which should be used for querying the specified node
     * @return string JSON encoded array of node path => label
     */
    public function masterPluginsAction($workspaceName = 'live', array $dimensions = array())
    {
        $this->response->setHeader('Content-Type', 'application/json');

        $contentContext = $this->createContentContext($workspaceName, $dimensions);
        $pluginNodes = $this->pluginService->getPluginNodesWithViewDefinitions($contentContext);

        $masterPlugins = array();
        if (is_array($pluginNodes)) {
            /** @var $pluginNode NodeInterface */
            foreach ($pluginNodes as $pluginNode) {
                if ($pluginNode->isRemoved()) {
                    continue;
                }
                $q = new FlowQuery(array($pluginNode));
                $page = $q->closest('[instanceof Neos.Neos:Document]')->get(0);
                if ($page === null) {
                    continue;
                }
                $translationHelper = new TranslationHelper();
                $masterPlugins[$pluginNode->getIdentifier()] = $translationHelper->translate(
                    'masterPlugins.nodeTypeOnPageLabel',
                    null,
                    ['nodeTypeName' => $translationHelper->translate($pluginNode->getNodeType()->getLabel()), 'pageLabel' => $page->getLabel()],
                    'Main',
                    'Neos.Neos'
               );
            }
        }
        return json_encode((object) $masterPlugins);
    }
}
