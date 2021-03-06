<?php

namespace Ladb\CoreBundle\Controller;

use Ladb\CoreBundle\Model\WatchableChildInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ladb\CoreBundle\Entity\Notification;
use Ladb\CoreBundle\Utils\TypableUtils;
use Ladb\CoreBundle\Utils\PaginatorUtils;

/**
 * @Route("/notifications")
 */
class NotificationController extends Controller {

	/**
	 * @Route("/", name="core_notification_list")
	 * @Route("/{filter}", requirements={"filter" = "[a-z-]+"}, name="core_notification_list_filter")
	 * @Route("/{filter}/{page}", requirements={"filter" = "[a-z-]+", "page" = "\d+"}, name="core_notification_list_filter_page")
	 * @Template("LadbCoreBundle:Notification:list-xhr.html.twig")
	 */
	public function listAction(Request $request, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();
		$notificationRepository = $om->getRepository(Notification::CLASS_NAME);
		$paginatorUtils = $this->get(PaginatorUtils::NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page, 10, 10);
		$limit = $paginatorUtils->computePaginatorLimit($page, 10, 10);
		$paginator = $notificationRepository->findPaginedByUser($this->getUser(), $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_notification_list_filter_page', array( 'filter' => $filter ), $page, $paginator->count());

		// Flag notification as listed

		$unlistedNotificationIds = array();
		foreach ($paginator as $notification) {
			if (!$notification->getIsListed()) {
				$unlistedNotificationIds[$notification->getId()] = true;
			}
			$notification->setIsListed(true);
		}

		$om->flush();

		// Reset user fresh notification count (only for default route)
		if ($page == 0 && $filter == "recent") {
			$this->getUser()->setFreshNotificationCount(0);
		}

		$om->flush();

		$parameters = array(
			'filter'                  => $filter,
			'prevPageUrl'             => $pageUrls->prev,
			'nextPageUrl'             => $pageUrls->next,
			'notifications'           => $paginator,
			'unlistedNotificationIds' => $unlistedNotificationIds,
		);

		if ($page > 0) {
			return $this->render('LadbCoreBundle:Notification:list-n-xhr.html.twig', $parameters);
		}

		return $parameters;
	}

	/**
	 * @Route("/{id}", requirements={"id" = "\d+"}, name="core_notification_show")
	 * @Template()
	 */
	public function showAction(Request $request, $id) {
		$om = $this->getDoctrine()->getManager();
		$notificationRepository = $om->getRepository(Notification::CLASS_NAME);

		$notification = $notificationRepository->findOneByIdJoinedOnActivity($id);
		if (is_null($notification)) {
			throw $this->createNotFoundException('Unable to find Notification entity (id='.$id.').');
		}

		// Update notification

		$notification->setIsShown(true);

		$om->flush();

		// Redirect

		$typableUtils = $this->get(TypableUtils::NAME);

		$activity = $notification->getActivity();
		$returnToUrl = $request->headers->get('referer');

		if ($activity instanceof \Ladb\CoreBundle\Entity\Activity\Comment) {
			$entity = $typableUtils->findTypable($activity->getComment()->getEntityType(), $activity->getComment()->getEntityId());
			if ($entity instanceof WatchableChildInterface) {
				$entity = $typableUtils->findTypable($entity->getParentEntityType(), $entity->getParentEntityId());
			}
			$returnToUrl = $typableUtils->getUrlAction($entity).'#ladb_comment_'.$activity->getComment()->getId();
		}

		else if ($activity instanceof \Ladb\CoreBundle\Entity\Activity\Contribute) {
			// TODO
		}

		else if ($activity instanceof \Ladb\CoreBundle\Entity\Activity\Follow) {
			$user = $activity->getUser();
			$returnToUrl = $this->generateUrl('core_user_show', array( 'username' => $user->getUsernameCanonical() ));
		}

		else if ($activity instanceof \Ladb\CoreBundle\Entity\Activity\Like) {
			$entity = $typableUtils->findTypable($activity->getLike()->getEntityType(), $activity->getLike()->getEntityId());
			$returnToUrl = $typableUtils->getUrlAction($entity);
		}

		else if ($activity instanceof \Ladb\CoreBundle\Entity\Activity\Mention) {
			// TODO
		}

		else if ($activity instanceof \Ladb\CoreBundle\Entity\Activity\Publish) {
			$entity = $typableUtils->findTypable($activity->getEntityType(), $activity->getEntityId());
			$returnToUrl = $typableUtils->getUrlAction($entity);
		}

		else if ($activity instanceof \Ladb\CoreBundle\Entity\Activity\Vote) {
			$entity = $typableUtils->findTypable($activity->getVote()->getParentEntityType(), $activity->getVote()->getParentEntityId());
			$returnToUrl = $typableUtils->getUrlAction($entity);
		}

		else if ($activity instanceof \Ladb\CoreBundle\Entity\Activity\Join) {
			$entity = $typableUtils->findTypable($activity->getJoin()->getEntityType(), $activity->getJoin()->getEntityId());
			$returnToUrl = $typableUtils->getUrlAction($entity);
		}

		return $this->redirect($returnToUrl);
	}

}