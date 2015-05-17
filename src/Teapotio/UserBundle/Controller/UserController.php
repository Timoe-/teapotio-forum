<?php

/**
 * Copyright (c) Thomas Potaire
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @category   Teapotio
 * @package    UserBundle
 * @author     Thomas Potaire
 */

namespace Teapotio\UserBundle\Controller;

use Teapotio\ImageBundle\Form\Type\ImageType;
use Teapotio\ImageBundle\Entity\Image;

use Teapotio\UserBundle\Entity\UserSettings;
use Teapotio\UserBundle\Form\Type\UserSettingsType;
use Teapotio\UserBundle\Form\Type\UserDescriptionType;
use Teapotio\UserBundle\Form\Type\UserGroupType;

use Teapotio\Components\Controller;

class UserController extends Controller
{

    public function addImageAction()
    {
        $this->throwAccessDeniedIfLoggedOut();

        $request = $this->get('request');

        $user = $this->get('teapotio.user')
                     ->find($request->attributes->get('userId'));

        $isCurrentUserAdmin = $this->get('teapotio.forum.access_permission')->isAdmin($this->getUser());
        $areUsersDifferent = $this->getUser()->getId() !== $user->getId();

        if ($isCurrentUserAdmin === false && $areUsersDifferent === true) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException();
        }

        $image = new Image();
        $form = $this->createForm(new ImageType(), $image);

        if ($request->getMethod() === 'POST') {
            $form->bind($request);
            if ($form->isValid() === true) {

                $em = $this->get('doctrine')
                           ->getManager();

                $image->setUser(
                    $this->get('security.context')->getToken()->getUser()
                );

                $this->get('teapotio.image')->save($image);

                $this->get('security.context')
                     ->getToken()
                     ->getUser()
                        ->addAvatar($image)
                        ->setDefaultAvatar($image);

                $em->persist($this->get('security.context')->getToken()->getUser());
                $em->flush();

                if ($request->isXmlHttpRequest() === true) {
                    return $this->renderJson(array('success' => 1, 'message' => $this->get('translator')->trans('Saved')));
                }
            }
        }

        if ($request->isXmlHttpRequest() === true) {
            return $this->renderJson(array('success' => 0, 'message' => $this->get('translator')->trans('Unsaved')));
        }

        return $this->redirect(
            $request->headers->get('referer')
        );
    }

    public function setDefaultImageAction($imageId)
    {
        $this->throwAccessDeniedIfLoggedOut();

        $request = $this->get('request');

        $user = $this->get('teapotio.user')
                     ->find($request->attributes->get('userId'));

        $isCurrentUserAdmin = $this->get('teapotio.forum.access_permission')->isAdmin($this->getUser());
        $areUsersDifferent = $this->getUser()->getId() !== $user->getId();

        if ($isCurrentUserAdmin === false && $areUsersDifferent === true) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException();
        }

        $this->get('teapotio.user')->setDefaultAvatarFromAvatars($user, (int)$imageId);

        if ($request->isXmlHttpRequest() === true) {
            return $this->renderJson(array('success' => 1, 'message' => $this->get('translator')->trans('Saved')));
        }

        return $this->redirect(
            $request->headers->get('referer')
        );
    }

    public function setDescriptionAction()
    {
        $this->throwAccessDeniedIfLoggedOut();

        $request = $this->get('request');

        $user = $this->get('teapotio.user')
                     ->find($request->attributes->get('userId'));

        $isCurrentUserAdmin = $this->get('teapotio.forum.access_permission')->isAdmin($this->getUser());
        $areUsersDifferent = $this->getUser()->getId() !== $user->getId();

        if ($isCurrentUserAdmin === false && $areUsersDifferent === true) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException();
        }

        $form = $this->createForm(new UserDescriptionType(), $user);

        if ($request->getMethod() === 'POST') {
            $form->bind($request);
            if ($form->isValid() === true) {

                $em = $this->get('doctrine')
                           ->getEntityManager();

                $em->persist($user);
                $em->flush();

                if ($request->isXmlHttpRequest() === true) {
                    return $this->renderJson(array('success' => 1, 'message' => $this->get('translator')->trans('Saved')));
                }
            }
        }

        if ($request->isXmlHttpRequest() === true) {
            return $this->renderJson(array('success' => 0, 'message' => $this->get('translator')->trans('Unsaved')));
        }

        return $this->redirect(
            $request->headers->get('referer')
        );
    }

    public function setSettingsAction()
    {
        $this->throwAccessDeniedIfLoggedOut();

        $request = $this->get('request');

        $user = $this->get('teapotio.user')
                     ->find($request->attributes->get('userId'));

        $isCurrentUserAdmin = $this->get('teapotio.forum.access_permission')->isAdmin($this->getUser());
        $areUsersDifferent = $this->getUser()->getId() !== $user->getId();

        if ($isCurrentUserAdmin === false && $areUsersDifferent === true) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException();
        }

        if ($user->getSettings() === null) {
            $user->setSettings(new UserSettings());
        }

        $form = $this->createForm(new UserSettingsType(), $user->getSettings());

        if ($request->getMethod() === 'POST') {
            $form->bind($request);
            if ($form->isValid() === true) {

                $em = $this->get('doctrine')
                           ->getManager();

                $user->getSettings()->setUser($user);

                if ($user->getSettings()->getBackgroundImage() !== null) {
                    $user->getSettings()->getBackgroundImage()->setUser($user);
                    $em->persist($user->getSettings()->getBackgroundImage());
                }

                $em->persist($user->getSettings());
                $em->flush();

                if ($request->isXmlHttpRequest() === true) {
                    return $this->renderJson(array('success' => 1, 'message' => $this->get('translator')->trans('Saved')));
                }
            }
        }

        if ($request->isXmlHttpRequest() === true) {
            return $this->renderJson(array('success' => 0, 'message' => $this->get('translator')->trans('Unsaved')));
        }

        return $this->redirect(
            $request->headers->get('referer')
        );
    }

    public function setGroupsAction()
    {
        $this->throwAccessDeniedIfNotAdmin();

        $request = $this->get('request');

        $user = $this->get('teapotio.user')
                     ->find($request->attributes->get('userId'));

        $form = $this->createForm(new UserGroupType(), $user);

        if ($request->getMethod() === 'POST') {
            $form->bind($request);
            if ($form->isValid() === true) {
                $this->get('teapotio.user')->save($user);

                if ($request->isXmlHttpRequest() === true) {
                    return $this->renderJson(array('success' => 1, 'message' => $this->get('translator')->trans('Saved')));
                }
            }
        }

        if ($request->isXmlHttpRequest() === true) {
            return $this->renderJson(array('success' => 0, 'message' => $this->get('translator')->trans('Unsaved')));
        }

        return $this->redirect(
            $request->headers->get('referer')
        );
    }
}
