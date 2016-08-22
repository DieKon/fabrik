<?php
/**
 * Fabrik Google Chart HTML View
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.visualization.chart
 * @copyright   Copyright (C) 2005-2016  Media A-Team, Inc. - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

namespace Fabrik\Plugins\Visualization\Chart\Views;

// No direct access
defined('_JEXEC') or die('Restricted access');

use Fabrik\Helpers\Html as HtmlHelper;
use Fabrik\Helpers\Text;

use \JFactory;
use \JHtml;
use \JViewLegacy;
use \JComponentHelper;
use \JError;

/**
 * Fabrik Google Chart HTML View
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.visualization.chart
 * @since       3.0
 */
class Html extends JViewLegacy
{
	/**
	 * Execute and display a template script.
	 *
	 * @param   string  $tpl  The name of the template file to parse; automatically searches through the template paths.
	 *
	 * @return  mixed  A string if successful, otherwise a JError object.
	 */

	public function display($tpl = 'default')
	{
		$app = JFactory::getApplication();
		$input = $app->input;
		$srcs = HtmlHelper::framework();
		$srcs['FbListFilter'] = 'media/com_fabrik/js/listfilter.js';
		$srcs['AdvancedSearch'] = 'media/com_fabrik/js/advanced-search.js';
		$model = $this->getModel();
		$usersConfig = JComponentHelper::getParams('com_fabrik');
		$model->setId($input->getInt('id', $usersConfig->get('visualizationid', $input->getInt('visualizationid', 0))));
		$this->row = $model->getVisualization();

		if (!$model->canView())
		{
			echo Text::_('JERROR_ALERTNOAUTHOR');

			return false;
		}

		if ($this->row->published == 0)
		{
			JError::raiseWarning(500, Text::_('JERROR_ALERTNOAUTHOR'));

			return '';
		}

		$this->requiredFiltersFound = $this->get('RequiredFiltersFound');

		if ($this->requiredFiltersFound)
		{
			$this->chart = $this->get('Chart');
		}
		else
		{
			$this->chart = '';
		}

		$params = $model->getParams();
		$this->params = $params;
		$viewName = $this->getName();
		$pluginManager = Worker::getPluginManager();
		$plugin = $pluginManager->getPlugIn('chart', 'visualization');
		$this->containerId = $this->get('ContainerId');
		$this->filters = $this->get('Filters');
		$this->showFilters = $model->showFilters();
		$this->filterFormURL = $this->get('FilterFormURL');

		$tpl = $params->get('chart_layout', $tpl);
		$this->_setPath('template', JPATH_ROOT . '/plugins/fabrik_visualization/Chart/Views/Chart/tmpl/' . $tpl);
		HtmlHelper::stylesheetFromPath('plugins/fabrik_visualization/Chart/Views/Chart/tmpl/' . $tpl . '/template.css');

		// Assign something to Fabrik.blocks to ensure we can clear filters
		$ref = $model->getJSRenderContext();
		$js = "$ref = {};";
		$js .= "\n" . "Fabrik.addBlock('$ref', $ref);";
		$js .= $model->getFilterJs();

		HtmlHelper::iniRequireJs($model->getShim());
		HtmlHelper::script($srcs, $js);
		echo parent::display();
	}
}