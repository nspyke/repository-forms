<?php

/**
 * This file is part of the eZ RepositoryForms package.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 *
 * @version //autogentag//
 */
namespace EzSystems\RepositoryForms\Form\Processor;

use eZ\Publish\API\Repository\ContentTypeService;
use eZ\Publish\API\Repository\Values\ContentType\FieldDefinitionCreateStruct;
use eZ\Publish\Core\Helper\FieldsGroups\FieldsGroupsList;
use EzSystems\RepositoryForms\Event\FormActionEvent;
use EzSystems\RepositoryForms\Event\RepositoryFormEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\RouterInterface;

class ContentTypeFormProcessor implements EventSubscriberInterface
{
    /**
     * @var ContentTypeService
     */
    private $contentTypeService;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var array
     */
    private $options;

    /**
     * @var \eZ\Publish\Core\Helper\FieldsGroups\FieldsGroupsList
     */
    private $groupsList;

    public function __construct(ContentTypeService $contentTypeService, RouterInterface $router, array $options = [])
    {
        $this->contentTypeService = $contentTypeService;
        $this->router = $router;
        $this->setOptions($options);
    }

    public function setGroupsList(FieldsGroupsList $groupsList)
    {
        $this->groupsList = $groupsList;
    }

    public function setOptions(array $options = [])
    {
        $this->options = $options + ['redirectRouteAfterPublish' => null];
    }

    public static function getSubscribedEvents()
    {
        return [
            RepositoryFormEvents::CONTENT_TYPE_UPDATE => 'processDefaultAction',
            RepositoryFormEvents::CONTENT_TYPE_ADD_FIELD_DEFINITION => 'processAddFieldDefinition',
            RepositoryFormEvents::CONTENT_TYPE_REMOVE_FIELD_DEFINITION => 'processRemoveFieldDefinition',
            RepositoryFormEvents::CONTENT_TYPE_PUBLISH => 'processPublishContentType',
            RepositoryFormEvents::CONTENT_TYPE_REMOVE_DRAFT => 'processRemoveContentTypeDraft',
        ];
    }

    public function processDefaultAction(FormActionEvent $event)
    {
        // Don't update anything if we just want to cancel the draft.
        if ($event->getClickedButton() === 'removeDraft') {
            return;
        }

        // Always update FieldDefinitions and ContentTypeDraft
        /** @var \EzSystems\RepositoryForms\Data\ContentTypeData $contentTypeData */
        $contentTypeData = $event->getData();
        $contentTypeDraft = $contentTypeData->contentTypeDraft;
        foreach ($contentTypeData->fieldDefinitionsData as $fieldDefData) {
            $this->contentTypeService->updateFieldDefinition($contentTypeDraft, $fieldDefData->fieldDefinition, $fieldDefData);
        }
        $contentTypeData->sortFieldDefinitions();
        $this->contentTypeService->updateContentTypeDraft($contentTypeDraft, $contentTypeData);
    }

    public function processAddFieldDefinition(FormActionEvent $event)
    {
        // Reload the draft, to make sure we include any changes made in the current form submit
        $contentTypeDraft = $this->contentTypeService->loadContentTypeDraft($event->getData()->contentTypeDraft->id);
        $fieldTypeIdentifier = $event->getForm()->get('fieldTypeSelection')->getData();

        $maxFieldPos = 0;
        foreach ($contentTypeDraft->fieldDefinitions as $existingFieldDef) {
            if ($existingFieldDef->position > $maxFieldPos) {
                $maxFieldPos = $existingFieldDef->position;
            }
        }

        $fieldDefCreateStruct = new FieldDefinitionCreateStruct([
            'fieldTypeIdentifier' => $fieldTypeIdentifier,
            'identifier' => sprintf('new_%s_%d', $fieldTypeIdentifier, count($contentTypeDraft->fieldDefinitions) + 1),
            'names' => [$event->getOption('languageCode') => 'New FieldDefinition'],
            'position' => $maxFieldPos + 1,
        ]);

        if (isset($this->groupsList)) {
            $fieldDefCreateStruct->fieldGroup = $this->groupsList->getDefaultGroup();
        }

        $this->contentTypeService->addFieldDefinition($contentTypeDraft, $fieldDefCreateStruct);
    }

    public function processRemoveFieldDefinition(FormActionEvent $event)
    {
        /** @var \eZ\Publish\API\Repository\Values\ContentType\ContentTypeDraft $contentTypeDraft */
        $contentTypeDraft = $event->getData()->contentTypeDraft;

        // Accessing FieldDefinition user selection through the form and not the data,
        // as "selected" is not a property of FieldDefinitionData.
        /** @var \Symfony\Component\Form\FormInterface $fieldDefForm */
        foreach ($event->getForm()->get('fieldDefinitionsData') as $fieldDefForm) {
            if ($fieldDefForm->get('selected')->getData() === true) {
                $this->contentTypeService->removeFieldDefinition($contentTypeDraft, $fieldDefForm->getData()->fieldDefinition);
            }
        }
    }

    public function processPublishContentType(FormActionEvent $event)
    {
        $contentTypeDraft = $event->getData()->contentTypeDraft;
        $this->contentTypeService->publishContentTypeDraft($contentTypeDraft);
        if (isset($this->options['redirectRouteAfterPublish'])) {
            $event->setResponse(
                new RedirectResponse($this->router->generate($this->options['redirectRouteAfterPublish']))
            );
        }
    }

    public function processRemoveContentTypeDraft(FormActionEvent $event)
    {
        $contentTypeDraft = $event->getData()->contentTypeDraft;
        $this->contentTypeService->deleteContentType($contentTypeDraft);
        if (isset($this->options['redirectRouteAfterPublish'])) {
            $event->setResponse(
                new RedirectResponse($this->router->generate($this->options['redirectRouteAfterPublish']))
            );
        }
    }
}
