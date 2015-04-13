<?php
/**
 * Fabrik Admin Elements Model
 *
 * @package     Joomla.Administrator
 * @subpackage  Fabrik
 * @copyright   Copyright (C) 2005-2013 fabrikar.com - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 * @since       1.6
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

use Joomla\Utilities\ArrayHelper;

require_once 'fabmodellist.php';

interface FabrikAdminModelElementsInterface
{
}

/**
 * Fabrik Admin Elements Model
 *
 * @package     Joomla.Administrator
 * @subpackage  Fabrik
 * @since       3.0
 */

abstract class FabrikAdminModelElements extends FabModelList implements  FabrikAdminModelElementsInterface
{
	/**
	 * Constructor.
	 *
	 * @param   array  $config  An optional associative array of configuration settings.
	 *
	 * @see		JController
	 *
	 * @since	1.6
	 */

	public function __construct($config = array())
	{
		if (empty($config['filter_fields']))
		{
			$config['filter_fields'] = array('e.id', 'e.name', 'e.label', 'e.show_in_list_summary', 'e.published', 'e.ordering', 'g.label',
				'e.plugin');
		}

		parent::__construct($config);
	}

	/**
	 * Build an SQL query to load the list data.
	 *
	 * @return  JDatabaseQuery
	 *
	 * @since	1.6
	 */
	protected function getListQuery()
	{
	}

	/**
	 * Method to auto-populate the model state.
	 *
	 * Note. Calling getState in this method will result in recursion.
	 *
	 * @param   string  $ordering   An optional ordering field.
	 * @param   string  $direction  An optional direction (asc|desc).
	 *
	 * @since	1.6
	 *
	 * @return  null
	 */

	protected function populateState($ordering = null, $direction = null)
	{
		// Initialise variables.
		$app = JFactory::getApplication('administrator');

		// Load the parameters.
		$params = JComponentHelper::getParams('com_fabrik');
		$this->setState('params', $params);

		$published = $app->getUserStateFromRequest($this->context . '.filter.published', 'filter_published', '');
		$this->setState('filter.published', $published);

		$search = $app->getUserStateFromRequest($this->context . '.filter.search', 'filter_search');
		$this->setState('filter.search', $search);

		// Load the form state
		$form = $app->getUserStateFromRequest($this->context . '.filter.form', 'filter_form', '');
		$this->setState('filter.form', $form);

		// Load the group state
		$group = $app->getUserStateFromRequest($this->context . '.filter.group', 'filter_group', '');
		$this->setState('filter.group', $group);

		// Load the show in list state
		$showinlist = $app->getUserStateFromRequest($this->context . '.filter.showinlist', 'filter_showinlist', '');
		$this->setState('filter.showinlist', $showinlist);

		// Load the plug-in state
		$plugin = $app->getUserStateFromRequest($this->context . '.filter.plugin', 'filter_plugin', '');
		$this->setState('filter.plugin', $plugin);

		// List state information.
		parent::populateState($ordering, $direction);
	}

	/**
	 * Get show in list options
	 *
	 * @return  array  of Jhtml select.options
	 */

	public function getShowInListOptions()
	{
		return array(JHtml::_('select.option', 0, FText::_('JNO')), JHtml::_('select.option', 1, FText::_('JYES')));
	}

	/**
	 * Get a list of plugin types to filter on
	 *
	 * @return  array  of select.options
	 */

	public function getPluginOptions()
	{
		$db = FabrikWorker::getDbo(true);
		$user = JFactory::getUser();
		$levels = implode(',', $user->getAuthorisedViewLevels());
		$query = $db->getQuery(true);
		$query->select('element AS value, element AS text')->from('#__extensions')->where('enabled >= 1')->where('type =' . $db->quote('plugin'))
			->where('state >= 0')->where('access IN (' . $levels . ')')->where('folder = ' . $db->quote('fabrik_element'))->order('text');
		$db->setQuery($query);
		$rows = $db->loadObjectList();

		return $rows;
	}

	/**
	 * Batch process element properties
	 *
	 * @param   array  $ids    element ids
	 * @param   array  $batch  element properties to set to
	 *
	 * @since   3.0.7
	 *
	 * @return  bool
	 */

	public function batch($ids, $batch)
	{
		ArrayHelper::toInteger($ids);

		foreach ($ids as $id)
		{
			$item = $this->getTable('Element');
			$item->load($id);
			$item->batch($batch);
		}
	}

	/**
	 * Stops internal id from being unpublished
	 *
	 * @param   array  $ids  Ids wanting to be unpublished
	 *
	 * @return  array  allowed ids
	 */
	public function canUnpublish($ids)
	{
		ArrayHelper::toInteger($ids);
		$blocked = array();
		$allowed = array();

		foreach ($ids as $id)
		{
			$item = $this->getTable('Element');
			$item->load($id);

			if ($item->plugin == 'internalid')
			{
				$blocked[] = $id;
			}
		}

		if (!empty($blocked))
		{
			$app = JFactory::getApplication();
			$app->enqueueMessage(FText::_('COM_FABRIK_CANT_UNPUBLISHED_PK_ELEMENT'), 'warning');
		}

		return array_diff($ids, $blocked);
	}
}
