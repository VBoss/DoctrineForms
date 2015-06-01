<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace Kdyby\DoctrineForms\Controls;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Kdyby;
use Kdyby\DoctrineForms\EntityFormMapper;
use Kdyby\DoctrineForms\IComponentMapper;
use Kdyby\DoctrineForms\ToManyContainer;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nette;
use Nette\ComponentModel\Component;



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class ToMany extends Nette\Object implements IComponentMapper
{

	/**
	 * @var EntityFormMapper
	 */
	private $mapper;



	public function __construct(EntityFormMapper $mapper)
	{
		$this->mapper = $mapper;
	}



	/**
	 * {@inheritdoc}
	 */
	public function load(ClassMetadata $meta, Component $component, $entity)
	{
		if (!$component instanceof ToManyContainer) {
			return FALSE;
		}

		if (!$collection = $this->getCollection($meta, $entity, $name = $component->getName())) {
			return FALSE;
		}

		$em = $this->mapper->getEntityManager();

		$component->bindCollection($entity, $collection);
		foreach ($collection as $relation) {
			$relationClassMetadata = $em->getClassMetadata(get_class($relation));

			if ($entityIdentifier = $this->getEntityIdentifier($relation)) {
				$relationComponentIdentifier = $component->getPopulateBy()
					? $relationClassMetadata->getFieldValue($relation, $component->getPopulateBy())
					: $entityIdentifier;

				/* @var Nette\Forms\Container $relationContainer */
				$relationContainer = $component[$relationComponentIdentifier];
				$this->mapper->load($relation, $relationContainer);

				$relationContainer
					->addHidden(Kdyby\DoctrineForms\IComponentMapper::IDENTIFIER)
					->setDefaultValue($entityIdentifier)
				;

				continue;
			}

			$this->mapper->load($relation, $component[ToManyContainer::NEW_PREFIX . $collection->indexOf($relation)]);
		}

		return TRUE;
	}



	/**
	 * {@inheritdoc}
	 */
	public function save(ClassMetadata $meta, Component $component, $entity)
	{
		if (!$component instanceof ToManyContainer) {
			return FALSE;
		}

		if (!$collection = $this->getCollection($meta, $entity, $component->getName())) {
			return FALSE;
		}

		$em = $this->mapper->getEntityManager();
		$UoW = $em->getUnitOfWork();
		$class = $meta->getAssociationTargetClass($component->getName());
		$relationMeta = $em->getClassMetadata($class);

		/** @var Nette\Forms\Container $container */
		foreach ($component->getComponents(FALSE, 'Nette\Forms\Container') as $container) {
			$identifier = $this->getIdentifier($container);

			$relation = $collection->filter(function ($entity) use ($UoW, $identifier) {
				$entityIdentifier = $this->getEntityIdentifier($entity);
				return $entityIdentifier == $identifier; // intentionally ==
			})->first();

			if (!$relation) { // entity was added from the client
				if (!$component->isAllowedRemove()) {
					continue;
				}

				$collection[] = $relation = $relationMeta->newInstance();
			}

			$this->mapper->save($relation, $container);
		}

		return TRUE;
	}



	/**
	 * @param ClassMetadata $meta
	 * @param object $entity
	 * @param string $field
	 * @return Collection
	 */
	private function getCollection(ClassMetadata $meta, $entity, $field)
	{
		if (!$meta->hasAssociation($field) || $meta->isSingleValuedAssociation($field)) {
			return FALSE;
		}

		$collection = $meta->getFieldValue($entity, $field);
		if ($collection === NULL) {
			$collection = new ArrayCollection();
			$meta->setFieldValue($entity, $field, $collection);
		}

		return $collection;
	}



	/**
	 * @param object $entity
	 * @return int|array
	 */
	private function getEntityIdentifier($entity)
	{
		$em = $this->mapper->getEntityManager();
		$UoW = $em->getUnitOfWork();

		$class = $em->getClassMetadata(get_class($entity));

		if ( ! $class->isIdentifierComposite) {
			return $UoW->getSingleIdentifierValue($entity);
		}

		$identifiers = $UoW->isInIdentityMap($entity)
			? $UoW->getEntityIdentifier($entity)
			: $class->getIdentifierValues($entity);

		return implode('_', array_values($identifiers));
	}



	/**
	 * @param Nette\Forms\Container $container
	 * @param string $identifierName
	 * @return string
	 */
	private function getIdentifier(Nette\Forms\Container $container, $identifierName = IComponentMapper::IDENTIFIER)
	{
		if (isset($container->components[$identifierName])) {
			return $container->components[$identifierName]
				->getValue();
		}

		return NULL;
	}

}
