<?php

/**
 * File containing the eZ\Publish\Core\Repository\SectionService class.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 *
 * @version //autogentag//
 */
namespace eZ\Publish\Core\Repository;

use eZ\Publish\API\Repository\Values\Content\SectionCreateStruct;
use eZ\Publish\API\Repository\Values\Content\ContentInfo;
use eZ\Publish\API\Repository\Values\Content\Section;
use eZ\Publish\API\Repository\Values\Content\SectionUpdateStruct;
use eZ\Publish\API\Repository\SectionService as SectionServiceInterface;
use eZ\Publish\API\Repository\Repository as RepositoryInterface;
use eZ\Publish\SPI\Persistence\Content\Section\Handler;
use eZ\Publish\SPI\Persistence\Content\Section as SPISection;
use eZ\Publish\Core\Base\Exceptions\InvalidArgumentValue;
use eZ\Publish\Core\Base\Exceptions\InvalidArgumentException;
use eZ\Publish\Core\Base\Exceptions\BadStateException;
use eZ\Publish\Core\Base\Exceptions\UnauthorizedException;
use eZ\Publish\API\Repository\Exceptions\NotFoundException as APINotFoundException;
use Exception;

/**
 * Section service, used for section operations.
 */
class SectionService implements SectionServiceInterface
{
    /**
     * @var \eZ\Publish\API\Repository\Repository
     */
    protected $repository;

    /**
     * @var \eZ\Publish\SPI\Persistence\Content\Section\Handler
     */
    protected $sectionHandler;

    /**
     * @var array
     */
    protected $settings;

    /**
     * Setups service with reference to repository object that created it & corresponding handler.
     *
     * @param \eZ\Publish\API\Repository\Repository $repository
     * @param \eZ\Publish\SPI\Persistence\Content\Section\Handler $sectionHandler
     * @param array $settings
     */
    public function __construct(RepositoryInterface $repository, Handler $sectionHandler, array $settings = array())
    {
        $this->repository = $repository;
        $this->sectionHandler = $sectionHandler;
        // Union makes sure default settings are ignored if provided in argument
        $this->settings = $settings + array(
            //'defaultSetting' => array(),
        );
    }

    /**
     * Creates a new Section in the content repository.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the current user user is not allowed to create a section
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException If the new identifier in $sectionCreateStruct already exists
     *
     * @param \eZ\Publish\API\Repository\Values\Content\SectionCreateStruct $sectionCreateStruct
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Section The newly created section
     */
    public function createSection(SectionCreateStruct $sectionCreateStruct)
    {
        if (!is_string($sectionCreateStruct->name) || empty($sectionCreateStruct->name)) {
            throw new InvalidArgumentValue('name', $sectionCreateStruct->name, 'SectionCreateStruct');
        }

        if (!is_string($sectionCreateStruct->identifier) || empty($sectionCreateStruct->identifier)) {
            throw new InvalidArgumentValue('identifier', $sectionCreateStruct->identifier, 'SectionCreateStruct');
        }

        if ($this->repository->hasAccess('section', 'edit') !== true) {
            throw new UnauthorizedException('section', 'edit');
        }

        try {
            $existingSection = $this->loadSectionByIdentifier($sectionCreateStruct->identifier);
            if ($existingSection !== null) {
                throw new InvalidArgumentException('sectionCreateStruct', 'section with specified identifier already exists');
            }
        } catch (APINotFoundException $e) {
            // Do nothing
        }

        $this->repository->beginTransaction();
        try {
            $spiSection = $this->sectionHandler->create(
                $sectionCreateStruct->name,
                $sectionCreateStruct->identifier
            );
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }

        return $this->buildDomainSectionObject($spiSection);
    }

    /**
     * Updates the given section in the content repository.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the current user user is not allowed to create a section
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException If the new identifier already exists (if set in the update struct)
     *
     * @param \eZ\Publish\API\Repository\Values\Content\Section $section
     * @param \eZ\Publish\API\Repository\Values\Content\SectionUpdateStruct $sectionUpdateStruct
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Section
     */
    public function updateSection(Section $section, SectionUpdateStruct $sectionUpdateStruct)
    {
        if ($sectionUpdateStruct->name !== null && !is_string($sectionUpdateStruct->name)) {
            throw new InvalidArgumentValue('name', $section->name, 'Section');
        }

        if ($sectionUpdateStruct->identifier !== null && !is_string($sectionUpdateStruct->identifier)) {
            throw new InvalidArgumentValue('identifier', $section->identifier, 'Section');
        }

        if ($this->repository->canUser('section', 'edit', $section) !== true) {
            throw new UnauthorizedException('section', 'edit');
        }

        if ($sectionUpdateStruct->identifier !== null) {
            try {
                $existingSection = $this->loadSectionByIdentifier($sectionUpdateStruct->identifier);

                // Allowing identifier update only for the same section
                if ($existingSection->id != $section->id) {
                    throw new InvalidArgumentException('sectionUpdateStruct', 'section with specified identifier already exists');
                }
            } catch (APINotFoundException $e) {
                // Do nothing
            }
        }

        $loadedSection = $this->loadSection($section->id);

        $this->repository->beginTransaction();
        try {
            $spiSection = $this->sectionHandler->update(
                $loadedSection->id,
                $sectionUpdateStruct->name ?: $loadedSection->name,
                $sectionUpdateStruct->identifier ?: $loadedSection->identifier
            );
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }

        return $this->buildDomainSectionObject($spiSection);
    }

    /**
     * Loads a Section from its id ($sectionId).
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException if section could not be found
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the current user user is not allowed to read a section
     *
     * @param mixed $sectionId
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Section
     */
    public function loadSection($sectionId)
    {
        if ($this->repository->hasAccess('section', 'view') !== true) {
            throw new UnauthorizedException('section', 'view');
        }

        $spiSection = $this->sectionHandler->load($sectionId);

        return $this->buildDomainSectionObject($spiSection);
    }

    /**
     * Loads all sections.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the current user user is not allowed to read a section
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Section[]
     */
    public function loadSections()
    {
        if ($this->repository->hasAccess('section', 'view') !== true) {
            throw new UnauthorizedException('section', 'view');
        }

        $spiSections = $this->sectionHandler->loadAll();

        $sections = array();
        foreach ($spiSections as $spiSection) {
            $sections[] = $this->buildDomainSectionObject($spiSection);
        }

        return $sections;
    }

    /**
     * Loads a Section from its identifier ($sectionIdentifier).
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException if section could not be found
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the current user user is not allowed to read a section
     *
     * @param string $sectionIdentifier
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Section
     */
    public function loadSectionByIdentifier($sectionIdentifier)
    {
        if (!is_string($sectionIdentifier) || empty($sectionIdentifier)) {
            throw new InvalidArgumentValue('sectionIdentifier', $sectionIdentifier);
        }

        if ($this->repository->hasAccess('section', 'view') !== true) {
            throw new UnauthorizedException('section', 'view');
        }

        $spiSection = $this->sectionHandler->loadByIdentifier($sectionIdentifier);

        return $this->buildDomainSectionObject($spiSection);
    }

    /**
     * Counts the contents which $section is assigned to.
     *
     * @param \eZ\Publish\API\Repository\Values\Content\Section $section
     *
     * @return int
     */
    public function countAssignedContents(Section $section)
    {
        return $this->sectionHandler->assignmentsCount($section->id);
    }

    /**
     * Assigns the content to the given section
     * this method overrides the current assigned section.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If user does not have access to view provided object
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $contentInfo
     * @param \eZ\Publish\API\Repository\Values\Content\Section $section
     */
    public function assignSection(ContentInfo $contentInfo, Section $section)
    {
        $loadedContentInfo = $this->repository->getContentService()->loadContentInfo($contentInfo->id);
        $loadedSection = $this->loadSection($section->id);

        if ($this->repository->canUser('section', 'assign', $loadedContentInfo, $loadedSection) !== true) {
            throw new UnauthorizedException(
                'section',
                'assign',
                array(
                    'name' => $loadedSection->name,
                    'content-name' => $loadedContentInfo->name,
                )
            );
        }

        $this->repository->beginTransaction();
        try {
            $this->sectionHandler->assign(
                $loadedSection->id,
                $loadedContentInfo->id
            );
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    /**
     * Deletes $section from content repository.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException If the specified section is not found
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the current user is not allowed to delete a section
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException If section can not be deleted
     *         because it is still assigned to some contents.
     *
     * @param \eZ\Publish\API\Repository\Values\Content\Section $section
     */
    public function deleteSection(Section $section)
    {
        $loadedSection = $this->loadSection($section->id);

        if ($this->repository->canUser('section', 'edit', $loadedSection) !== true) {
            throw new UnauthorizedException('section', 'edit', array('sectionId' => $loadedSection->id));
        }

        if ($this->countAssignedContents($loadedSection) > 0) {
            throw new BadStateException('section', 'section is still assigned to content');
        }

        $this->repository->beginTransaction();
        try {
            $this->sectionHandler->delete($loadedSection->id);
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    /**
     * Instantiates a new SectionCreateStruct.
     *
     * @return \eZ\Publish\API\Repository\Values\Content\SectionCreateStruct
     */
    public function newSectionCreateStruct()
    {
        return new SectionCreateStruct();
    }

    /**
     * Instantiates a new SectionUpdateStruct.
     *
     * @return \eZ\Publish\API\Repository\Values\Content\SectionUpdateStruct
     */
    public function newSectionUpdateStruct()
    {
        return new SectionUpdateStruct();
    }

    /**
     * Builds API Section object from provided SPI Section object.
     *
     * @param \eZ\Publish\SPI\Persistence\Content\Section $spiSection
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Section
     */
    protected function buildDomainSectionObject(SPISection $spiSection)
    {
        return new Section(
            array(
                'id' => $spiSection->id,
                'identifier' => $spiSection->identifier,
                'name' => $spiSection->name,
            )
        );
    }
}
