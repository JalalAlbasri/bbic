<?php
/**
 * @package     DOCman
 * @copyright   Copyright (C) 2011 - 2014 Timble CVBA. (http://www.timble.net)
 * @license     GNU GPLv3 <http://www.gnu.org/licenses/gpl.html>
 * @link        http://www.joomlatools.com
 */

/**
 * Routes requests marked with routed=1 through com_files
 *
 */
class ComDocmanDispatcherBehaviorRoutable extends KControllerBehaviorAbstract
{
    protected function _setContainer(KControllerContextInterface $context)
    {
        $query = $context->request->query;

        if (!in_array($query->container, array('docman-files', 'docman-icons', 'docman-images'))) {
            $query->container = 'docman-files';
        }

        $container = $this->getObject('com:files.model.containers')
            ->slug($query->container)
            ->fetch();

        if (!is_dir($container->fullpath))
        {
            throw new RuntimeException($this->getObject('translator')->translate(
                'Document path is missing. Please make sure there is a folder named {folder} on your site root.', array(
                'folder' => $container->path
            )));
        }

        if ($query->layout === 'select')
        {
            $query->types = array('image');

            if ($query->container === 'docman-files') {
                $query->types = array('image', 'file');
            }
        }
    }

    protected function _attachBehaviors(KControllerContextInterface $context)
    {
        if ($context->request->query->container === 'docman-icons')
        {
            $this->getIdentifier('com:files.controller.file')->getConfig()->append(array(
                'behaviors' => array('com://admin/docman.controller.behavior.resizable')
            ));
        }

        // Use our own ACL and cache the hell out of JSON requests
        $behaviors = array(
            'permissible' => array(
                'permission' => 'com://admin/docman.controller.permission.file'
            )
        );

        if ($context->request->query->container === 'docman-files')
        {
            $behaviors['com:files.controller.behavior.cacheable'] = array(
                'group' => 'com_docman.files'
            );

            // If the upload_folder parameter is set, we change the container path so results are different
            $app = JFactory::getApplication();
            if ($app->isSite() && $app->getMenu()->getActive()->params->get('upload_folder')) {
                $behaviors['com:files.controller.behavior.cacheable']['only_clear'] = true;
            }

            // Only register this alias here as it should not be used for file downloads
            $this->getObject('manager')->registerAlias('com://admin/docman.model.containers', 'com:files.model.containers');
        }

        foreach (array('file', 'folder', 'node', 'proxy', 'thumbnail', 'container') as $name)
        {
            $this->getIdentifier('com:files.controller.'.$name)->getConfig()->append(array(
                'behaviors' => $behaviors
            ));
        }
    }

    protected function _beforeDispatch(KControllerContextInterface $context)
    {
        $query = $context->request->query;

        if ($query->routed ||
            ($query->view === 'files' && (!$query->has('layout') || in_array($query->layout, array('default', 'select')))))
        {
            $layout = $query->layout;
            $tmpl   = $query->tmpl;

            $this->_setContainer($context);
            $this->_attachBehaviors($context);

            $config = array(
                'grid' => array(
                    'layout' => ($layout === 'select' ? 'compact' : 'details')
                ),
                'router' => array(
                    'defaults' => array()
                )
            );

            if ($menu = JFactory::getApplication()->getMenu()->getActive())
            {
                $base_path = $context->request->getUrl()->toString(KHttpUrl::AUTHORITY);
                $menu_path = JRoute::_('index.php?option=com_docman&Itemid='.$menu->id, false);

                $config['base_url'] = $base_path.$menu_path;
                $config['router']['defaults']['Itemid'] = $menu->id;
                // Disable persistency if an upload folder is set for the menu item
                if ($menu->params->get('upload_folder')) {
                    $config['persistent'] = false;
                }
            }

            $query->tmpl   = 'joomla';
            $query->config = $config;
            $query->layout = $layout === 'select' ? 'compact' : 'com://admin/docman.files.wrapper';

            if (JFactory::getApplication()->isSite()
                && !$this->getController()->canManage()
                && $query->layout === 'compact'
                && $query->container === 'docman-files'
            ) {
                $query->layout = 'compact_upload';

                $config['initial_response'] = false;
                $query->config = $config;
            }

            $context->param = 'com:files.dispatcher.http';
            $this->getMixer()->execute('forward', $context);

            $query->layout = $layout;
            $query->tmpl   = $tmpl;

            if ($query->routed)
            {
                // Work-around the bug here: http://joomlacode.org/gf/project/joomla/tracker/?action=TrackerItemEdit&tracker_item_id=28249
                JFactory::getSession()->set('com.files.fix.the.session.bug', microtime(true));

                return false;
            }
        }
    }
}
