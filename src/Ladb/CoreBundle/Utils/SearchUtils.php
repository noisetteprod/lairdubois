<?php

namespace Ladb\CoreBundle\Utils;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyPath;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Elastica\Query;
use Elastica\Filter\BoolNot;
use Elastica\Filter\Ids;
use Ladb\CoreBundle\Model\IndexableInterface;

class SearchUtils extends AbstractContainerAwareUtils {

	const NAME = 'ladb_core.search_utils';

	/////

	private function _getObjectPersister($entity) {
		$classname = strtolower(get_class($entity));
		if (preg_match('@\\\\([\w]+)$@', $classname, $matches)) {
			$typeName = $matches[1];
			return $this->get('fos_elastica.object_persister.ladb.'.$typeName);
		}
		return null;
	}

	/////

	public function insertEntityToIndex(IndexableInterface $entity) {
		if ($entity->isIndexable()) {
			$objectPersister = $this->_getObjectPersister($entity);
			if (!is_null($objectPersister)) {
				try {
					$objectPersister->insertOne($entity);
				} catch (\Exception $e) {
				}
			}
		}
	}

	public function replaceEntityInIndex(IndexableInterface $entity) {
		if ($entity->isIndexable()) {
			$objectPersister = $this->_getObjectPersister($entity);
			if (!is_null($objectPersister)) {
				try {
					$objectPersister->replaceOne($entity);
				} catch (\Exception $e) {
				}
			}
		}
	}

	public function deleteEntityFromIndex(IndexableInterface $entity) {
		$objectPersister = $this->_getObjectPersister($entity);
		if (!is_null($objectPersister)) {
			try {
				$objectPersister->deleteOne($entity);
			} catch (\Exception $e) {
			}
		}
	}

	/////

	private function _buildElasticaQuery(&$filters, &$sort, $offset, $limit, $excludedIds = null) {
		if (is_null($filters) && is_null($sort)) {
			return null;
		}

		$query = null;
		if (empty($filters) && !is_null($sort)) {
			$query = new \Elastica\Query\MatchAll();
		} else {
			$query = new \Elastica\Query\Bool();
			foreach ($filters as $filter) {
				$query->addMust($filter);
			}
		}

		$elasticaQuery = Query::create($query);
		if (!is_null($sort)) {
			$elasticaQuery->addSort($sort);
		}
		$elasticaQuery->setFrom($offset);
		if ($limit > 0) {
			$elasticaQuery->setSize($limit);
		}

		if (!is_null($excludedIds) && is_array($excludedIds) && !empty($excludedIds)) {
			$ids = new Ids();
			foreach ($excludedIds as $excludedId) {
				$ids->addId($excludedId);
			}
			$elasticaQuery->setPostFilter(new BoolNot($ids));
		}

		return $elasticaQuery;
	}

	public function searchEntitiesCount($filters, $sort, $typeName, $excludedIds = null) {

		$elasticaQuery = $this->_buildElasticaQuery($filters, $sort, 0, 0, $excludedIds);
		if (is_null($elasticaQuery)) {
			return 0;
		}

		// Count
		$type = $this->get($typeName);
		try {
			$count = $type->count($elasticaQuery);
		} catch (\Exception $e) {
			return 0;
		}

		return $count;
	}

	public function searchEntities(&$filters, &$sort, $typeName, $entityClassName, $offset, $limit, $excludedIds = null) {

		$result = new \stdClass();
		$result->resultSet = null;
		$result->entities = array();

		$elasticaQuery = $this->_buildElasticaQuery($filters, $sort, $offset, $limit, $excludedIds);
		if (is_null($elasticaQuery)) {
			return $result;
		}

		// Search
		$type = $this->get($typeName);
		try {
			$resultSet = $type->search($elasticaQuery);
		} catch (\Exception $e) {
			return $result;
		}

		// Extract Ids
		$ids = array();
		foreach ($resultSet->getResults() as $result) {
			$ids[] = $result->getId();
		}

		// Retrieve entities
		$entities = count($ids) == 0 ? array() : $this->getDoctrine()->getManager()->getRepository($entityClassName)->findByIds($ids);

		// Reorder entities
		$identifierPropertyPath = new PropertyPath('id');
		$propertyAccessor = PropertyAccess::createPropertyAccessor();
		$idPos = array_flip($ids);
		usort($entities, function($a, $b) use ($idPos, $identifierPropertyPath, $propertyAccessor) {
			return $idPos[$propertyAccessor->getValue($a, $identifierPropertyPath)] > $idPos[$propertyAccessor->getValue($b, $identifierPropertyPath)];
		});

		$result->resultSet = $resultSet;
		$result->entities = $entities;

		return $result;
	}

	/////

	private function _parseQueryRequest(Request $request, $parseFacets = true) {
		$q = $request->get('q', '');

		// Parse facets
		$facets = null;
		if ($parseFacets) {

			$facets = array();
			preg_match_all('/(?:@([^\s]+):(?:\"([^\"]+)\"|([^\s]+))|@([^\s]+)|\"([^\"]+)\"|([^\s]+))/', $q, $matches);
			for ($i = 0; $i < count($matches[0]); $i++) {

				$facet = new \stdClass();
				$facet->name = null;

				if (!empty($matches[1][$i])) {
					$facet->name = $matches[1][$i];
					if (!empty($matches[2][$i])) {
						$facet->value = $matches[2][$i];
					} elseif (!empty($matches[3][$i])) {
						$facet->value = $matches[3][$i];
					} else {
						continue;
					}
				} else if (!empty($matches[4][$i])) {
					$facet->name = $matches[4][$i];
				} else {
					if (!empty($matches[5][$i])) {
						$facet->value = $matches[5][$i];
					} elseif (!empty($matches[6][$i])) {
						$facet->value = $matches[6][$i];
					} else {
						continue;
					}
				}

				$facets[] = $facet;
			}

		}

		// Compute excluded IDs
		$ex = $request->get('ex');
		$excludedIds = array();
		$strIds = explode(',', $ex);
		foreach ($strIds as $strId) {
			$intId = intval(trim($strId));
			if ($intId > 0) {
				$excludedIds[] = $intId;
			}
		}

		return array(
			'q'           => $q,
			'facets'      => $facets,
			'ex'          => $ex,
			'excludedIds' => $excludedIds,
		);
	}

	public function searchPaginedEntities(Request $request, $page, $queryCallback, $defaultsCallBack, $typeName, $entityClassName, $route, $parameters = array(), $excludedIds = null) {

		// Parse request
		$queryParameters = $this->_parseQueryRequest($request);

		// Export request parameters
		$excludedIds = is_array($excludedIds) ? array_merge($queryParameters['excludedIds'], $excludedIds) : $queryParameters['excludedIds'];
		$ex = implode(',', $excludedIds);
		$q = $queryParameters['q'];

		// Compute filters and sort
		$filters = array();
		$sort = null;
		$defaults = true;
		foreach ($queryParameters['facets'] as $facet) {
			$queryCallback($facet, $filters, $sort);
		}
		if (empty($filters) && is_null($sort)) {
			$defaultsCallBack($filters, $sort);
		} else {
			$defaults = false;
		}

		// Setup pagination
		$paginatorUtils = $this->get(PaginatorUtils::NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);

		// Search entities
		$searchResult = $this->searchEntities($filters, $sort, $typeName, $entityClassName, $offset, $limit, $excludedIds);

		if (is_null($route) || is_null($searchResult->resultSet)) {
			$pageUrls = new \stdClass();
			$pageUrls->prev = 0;
			$pageUrls->next = 0;
		} else {
			$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl($route, array_merge($parameters, array( 'q' => $q, 'ex' => $ex )), $page, $searchResult->resultSet->getTotalHits());
		}

		return array(
			'q'           => $q,
			'ex'          => $ex,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'totalHits'   => $defaults ? -1 : (is_null($searchResult->resultSet) ? 0 : $searchResult->resultSet->getTotalHits()),
			'resultSet'   => $searchResult->resultSet,
			'entities'    => $searchResult->entities,
		);
	}

}