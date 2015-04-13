<?php
/**
 * Plug-in to render fileupload element
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.element.fileupload
 * @copyright   Copyright (C) 2005-2013 fabrikar.com - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

use Joomla\String\String;
use Joomla\Utilities\ArrayHelper;

require_once COM_FABRIK_FRONTEND . '/helpers/image.php';

define("FU_DOWNLOAD_SCRIPT_NONE", '0');
define("FU_DOWNLOAD_SCRIPT_TABLE", '1');
define("FU_DOWNLOAD_SCRIPT_DETAIL", '2');
define("FU_DOWNLOAD_SCRIPT_BOTH", '3');

$logLvl = JLog::ERROR + JLog::EMERGENCY + JLog::WARNING;
JLog::addLogger(array('text_file' => 'fabrik.element.fileupload.log.php'), $logLvl, array('com_fabrik.element.fileupload'));

/**
 * Plug-in to render fileupload element
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.element.fileupload
 * @since       3.0
 */

class PlgFabrik_ElementFileupload extends PlgFabrik_Element
{
	/**
	 * Storage method adaptor object (filesystem/amazon s3)
	 * needs to be public as models have to see it
	 *
	 * @var object
	 */
	public $storage = null;

	/**
	 * Is the element an upload element
	 *
	 * @var bool
	 */
	protected $is_upload = true;

	/**
	 * Does the element store its data in a join table (1:n)
	 *
	 * @return  bool
	 */

	public function isJoin()
	{
		$params = $this->getParams();

		if ($params->get('ajax_upload') && (int) $params->get('ajax_max', 4) > 1)
		{
			return true;
		}
		else
		{
			return parent::isJoin();
		}
	}

	/**
	 * Determines if the data in the form element is used when updating a record
	 *
	 * @param   mixed  $val  Element form data
	 *
	 * @return  bool  True if ignored on update, default = false
	 */

	public function ignoreOnUpdate($val)
	{
		$app = JFactory::getApplication();
		$input = $app->input;

		// Check if its a CSV import if it is allow the val to be inserted
		if ($input->get('task') === 'makeTableFromCSV' || $this->getListModel()->importingCSV)
		{
			return false;
		}

		$fullName = $this->getFullName(true, false);
		$params = $this->getParams();
		$groupModel = $this->getGroupModel();
		$return = false;

		if ($groupModel->canRepeat())
		{
			/*$$$rob could be the case that we aren't uploading an element by have removed
			 *a repeat group (no join) with a file upload element, in this case processUpload has the correct
			 *file path settings.
			 */
			return false;
		}
		else
		{
			if ($groupModel->isJoin())
			{
				$name = $this->getFullName(true, false);
				$joinid = $groupModel->getGroup()->join_id;
				$fileJoinData = FArrayHelper::getValue($_FILES['join']['name'], $joinid, array());
				$fdata = FArrayHelper::getValue($fileJoinData, $name);

				// $fdata = $_FILES['join']['name'][$joinid][$name];
			}
			else
			{
				$fdata = @$_FILES[$fullName]['name'];
			}

			if ($fdata == '')
			{
				if ($this->canCrop() == false)
				{
					// Was stopping saving of single ajax upload image
					// return true;
				}
				else
				{
					/*if we can crop we need to store the cropped coordinated in the field data
					 * @see onStoreRow();
					 * above depreciated - not sure what to return here for the moment
					 */
					return false;
				}
			}
			else
			{
				return false;
			}
		}

		return $return;
	}

	/**
	 * Remove the reference to the file from the db table - leaves the file on the server
	 *
	 * @since  3.0.7
	 *
	 * @return  void
	 */

	public function onAjax_clearFileReference()
	{
		$app = JFactory::getApplication();
		$rowId = (array) $app->input->get('rowid');
		$this->loadMeForAjax();
		$col = $this->getFullName(false, false);
		$listModel = $this->getListModel();
		$listModel->updateRows($rowId, $col, '');
	}

	/**
	 * Get the class to manage the form element
	 * to ensure that the file is loaded only once
	 *
	 * @param   array   &$srcs   Scripts previously loaded
	 * @param   string  $script  Script to load once class has loaded
	 * @param   array   &$shim   Dependant class names to load before loading the class - put in requirejs.config shim
	 *
	 * @return void
	 */

	public function formJavascriptClass(&$srcs, $script = '', &$shim = array())
	{
		$s = new stdClass;
		$s->deps = array('fab/element');
		$params = $this->getParams();

		if ($params->get('ajax_upload'))
		{
			$runtimes = $params->get('ajax_runtime', 'html5');
			$folder = 'element/fileupload/lib/plupload/js/';
			$plupShim = new stdClass;
			$plupShim->deps = array($folder . 'plupload');
			$s->deps[] = $folder . 'plupload';

			if (strstr($runtimes, 'html5'))
			{
				$s->deps[] = $folder . 'plupload.html5';
				$shim[$folder . 'plupload.html5'] = $plupShim;
			}

			if (strstr($runtimes, 'html4'))
			{
				$s->deps[] = $folder . 'plupload.html4';
				$shim[$folder . 'plupload.html4'] = $plupShim;
			}

			if (strstr($runtimes, 'flash'))
			{
				$s->deps[] = $folder . 'plupload.flash';
				$shim[$folder . 'plupload.flash'] = $plupShim;
			}

			if (strstr($runtimes, 'silverlight'))
			{
				$s->deps[] = $folder . 'plupload.silverlight';
				$shim[$folder . 'plupload.silverlight'] = $plupShim;
			}

			if (strstr($runtimes, 'browserplus'))
			{
				$s->deps[] = $folder . 'plupload.browserplus';
				$shim[$folder . 'plupload.browserplus'] = $plupShim;
			}
		}

		if (array_key_exists('element/fileupload/fileupload', $shim) && isset($shim['element/fileupload/fileupload']->deps))
		{
			$shim['element/fileupload/fileupload']->deps = array_values(array_unique(array_merge($shim['element/fileupload/fileupload']->deps, $s->deps)));
		}
		else
		{
			$shim['element/fileupload/fileupload'] = $s;
		}

		if ($this->requiresSlideshow())
		{
			FabrikHelperHTML::slideshow();
		}

		parent::formJavascriptClass($srcs, $script, $shim);

		// $$$ hugh - added this, and some logic in the view, so we will get called on a per-element basis
		return false;
	}

	/**
	 * Returns javascript which creates an instance of the class defined in formJavascriptClass()
	 *
	 * @param   int  $repeatCounter  Repeat group counter
	 *
	 * @return  array
	 */

	public function elementJavascript($repeatCounter)
	{
		$params = $this->getParams();
		$id = $this->getHTMLId($repeatCounter);
		FabrikHelperHTML::mcl();
		$j3 = FabrikWorker::j3();

		$element = $this->getElement();
		$paramsKey = $this->getFullName(true, false);
		$paramsKey = Fabrikstring::rtrimword($paramsKey, $this->getElement()->name);
		$paramsKey .= 'params';
		$formData = $this->getFormModel()->data;
		$imgParams = FArrayHelper::getValue($formData, $paramsKey);

		// Above paramsKey stuff looks really wonky - lets test if null and use something which seems to build the correct key
		if (is_null($imgParams))
		{
			$paramsKey = $this->getFullName(true, false) . '___params';
			$imgParams = FArrayHelper::getValue($formData, $paramsKey);
		}

		$value = $this->getValue(array(), $repeatCounter);
		$value = is_array($value) ? $value : FabrikWorker::JSONtoData($value, true);
		$value = $this->checkForSingleCropValue($value);

		// Repeat_image_repeat_image___params
		$rawvalues = count($value) == 0 ? array() : array_fill(0, count($value), 0);
		$fdata = $this->getFormModel()->data;
		$rawkey = $this->getFullName(true, false) . '_raw';
		$rawvalues = FArrayHelper::getValue($fdata, $rawkey, $rawvalues);

		if (!is_array($rawvalues))
		{
			$rawvalues = explode(GROUPSPLITTER, $rawvalues);
		}
		else
		{
			/*
			 * $$$ hugh - nasty hack for now, if repeat group with simple
			 * uploads, all raw values are in an array in $rawvalues[0]
			 */
			if (is_array(FArrayHelper::getValue($rawvalues, 0)))
			{
				$rawvalues = $rawvalues[0];
			}
		}

		if (!is_array($imgParams))
		{
			$imgParams = explode(GROUPSPLITTER, $imgParams);
		}

		$oFiles = new stdClass;
		$iCounter = 0;

		// Failed validation for ajax upload elements
		if (is_array($value) && array_key_exists('id', $value))
		{
			$imgParams = array_values($value['crop']);
			$value = array_keys($value['id']);
			$rawvalues = $value;
		}

		for ($x = 0; $x < count($value); $x++)
		{
			if (is_array($value))
			{
				if (array_key_exists($x, $value) && $value[$x] !== '')
				{
					if (is_array($value[$x]))
					{
						// From failed validation
						foreach ($value[$x]['id'] as $tkey => $parts)
						{
							$o = new stdClass;
							$o->id = 'alreadyuploaded_' . $element->id . '_' . $iCounter;
							$o->name = array_pop(explode(DIRECTORY_SEPARATOR, $tkey));
							$o->path = $tkey;

							if ($fileinfo = $this->getStorage()->getFileInfo($o->path))
							{
								$o->size = $fileinfo['filesize'];
							}
							else
							{
								$o->size = 'unknown';
							}

							$o->type = strstr($fileinfo['mime_type'], 'image/') ? 'image' : 'file';
							$o->url = $this->getStorage()->pathToURL($tkey);
							$o->recordid = $rawvalues[$x];
							$o->params = json_decode($value[$x]['crop'][$tkey]);
							$oFiles->$iCounter = $o;
							$iCounter++;
						}
					}
					else
					{
						if (is_object($value[$x]))
						{
							// Single crop image (not sure about the 0 settings in here)
							$parts = explode(DIRECTORY_SEPARATOR, $value[$x]->file);
							$o = new stdClass;
							$o->id = 'alreadyuploaded_' . $element->id . '_0';
							$o->name = array_pop($parts);
							$o->path = $value[$x]->file;

							if ($fileinfo = $this->getStorage()->getFileInfo($o->path))
							{
								$o->size = $fileinfo['filesize'];
							}
							else
							{
								$o->size = 'unknown';
							}

							$o->type = strstr($fileinfo['mime_type'], 'image/') ? 'image' : 'file';
							$o->url = $this->getStorage()->pathToURL($value[$x]->file);
							$o->recordid = 0;
							$o->params = json_decode($value[$x]->params);
							$oFiles->$iCounter = $o;
							$iCounter++;
						}
						else
						{
							$parts = explode('/', $value[$x]);
							$o = new stdClass;
							$o->id = 'alreadyuploaded_' . $element->id . '_' . $rawvalues[$x];
							$o->name = array_pop($parts);
							$o->path = $value[$x];

							if ($fileinfo = $this->getStorage()->getFileInfo($o->path))
							{
								$o->size = $fileinfo['filesize'];
							}
							else
							{
								$o->size = 'unknown';
							}

							$o->type = strstr($fileinfo['mime_type'], 'image/') ? 'image' : 'file';
							$o->url = $this->getStorage()->pathToURL($value[$x]);
							$o->recordid = $rawvalues[$x];
							$o->params = json_decode(FArrayHelper::getValue($imgParams, $x, '{}'));
							$oFiles->$iCounter = $o;
							$iCounter++;
						}
					}
				}
			}
		}

		$opts = $this->getElementJSOptions($repeatCounter);
		$opts->id = $this->getId();

		if ($this->isJoin())
		{
			$opts->isJoin = true;
			$opts->joinId = $this->getJoinModel()->getJoin()->id;
		}

		$opts->elid = $element->id;
		$opts->defaultImage = $params->get('default_image');
		$opts->folderSelect = $params->get('upload_allow_folderselect', 0);
		$opts->dir = JPATH_SITE . '/' . $params->get('ul_directory');
		$opts->ajax_upload = (bool) $params->get('ajax_upload', false);
		$opts->ajax_runtime = $params->get('ajax_runtime', 'html5');
		$opts->ajax_silverlight_path = COM_FABRIK_LIVESITE . 'plugins/fabrik_element/fileupload/lib/plupload/js/plupload.flash.swf';
		$opts->ajax_flash_path = COM_FABRIK_LIVESITE . 'plugins/fabrik_element/fileupload/lib/plupload/js/plupload.flash.swf';
		$opts->max_file_size = (float) $params->get('ul_max_file_size');
		$opts->device_capture = (float) $params->get('ul_device_capture');
		$opts->ajax_chunk_size = (int) $params->get('ajax_chunk_size', 0);
		$opts->filters = $this->ajaxFileFilters();
		$opts->crop = $this->canCrop();
		$opts->canvasSupport = FabrikHelperHTML::canvasSupport();
		$opts->elementName = $this->getFullName();
		$opts->cropwidth = (int) $params->get('fileupload_crop_width');
		$opts->cropheight = (int) $params->get('fileupload_crop_height');
		$opts->ajax_max = (int) $params->get('ajax_max', 4);
		$opts->dragdrop = true;
		$icon = $j3 ? 'picture' : 'image.png';
		$resize = $j3 ? 'expand-2' : 'resize.png';
		$opts->previewButton = FabrikHelperHTML::image($icon, 'form', @$this->tmpl, array('alt' => FText::_('PLG_ELEMENT_FILEUPLOAD_VIEW')));
		$opts->resizeButton = FabrikHelperHTML::image($resize, 'form', @$this->tmpl, array('alt' => FText::_('PLG_ELEMENT_FILEUPLOAD_RESIZE')));
		$opts->files = $oFiles;

		$opts->winWidth = (int) $params->get('win_width', 400);
		$opts->winHeight = (int) $params->get('win_height', 400);
		$opts->elementShortName = $element->name;
		$opts->listName = $this->getListModel()->getTable()->db_table_name;
		$opts->useWIP = (bool) $params->get('upload_use_wip', '0') == '1';
		$opts->page_url = COM_FABRIK_LIVESITE;

		JText::script('PLG_ELEMENT_FILEUPLOAD_MAX_UPLOAD_REACHED');
		JText::script('PLG_ELEMENT_FILEUPLOAD_DRAG_FILES_HERE');
		JText::script('PLG_ELEMENT_FILEUPLOAD_UPLOAD_ALL_FILES');
		JText::script('PLG_ELEMENT_FILEUPLOAD_RESIZE');
		JText::script('PLG_ELEMENT_FILEUPLOAD_CROP_AND_SCALE');
		JText::script('PLG_ELEMENT_FILEUPLOAD_PREVIEW');
		JText::script('PLG_ELEMENT_FILEUPLOAD_CONFIRM_SOFT_DELETE');
		JText::script('PLG_ELEMENT_FILEUPLOAD_CONFIRM_HARD_DELETE');
		JText::script('PLG_ELEMENT_FILEUPLOAD_FILE_TOO_LARGE_SHORT');

		return array('FbFileUpload', $id, $opts);
	}

	/**
	 * Create Plupload js options for file extension filters
	 *
	 * @return  array
	 */

	protected function ajaxFileFilters()
	{
		$return = new stdClass;
		$exts = $this->_getAllowedExtension();

		$return->title = 'Allowed files';
		$return->extensions = implode(',', $exts);

		return array($return);
	}

	/**
	 * Can the plug-in crop. Based on parameters and browser check (IE8 or less has no canvas support)
	 *
	 * @since   3.0.9
	 *
	 * @return boolean
	 */

	protected function canCrop()
	{
		$params = $this->getParams();

		if (!FabrikHelperHTML::canvasSupport())
		{
			return false;
		}

		return (bool) $params->get('fileupload_crop', 0);
	}

	/**
	 * Shows the data formatted for the list view
	 *
	 * @param   string    $data      Elements data
	 * @param   stdClass  &$thisRow  All the data in the lists current row
	 *
	 * @return  string	Formatted value
	 */

	public function renderListData($data, stdClass &$thisRow)
	{
		$data = FabrikWorker::JSONtoData($data, true);
		$params = $this->getParams();
		$rendered = '';
		static $id_num = 0;

		// $$$ hugh - have to run through rendering even if data is empty, in case default image is being used.
		if (empty($data))
		{
			$data[0] = $this->_renderListData('', $thisRow, 0);
		}
		else
		{
			/**
			 * 2 == 'slideshow' ('carousel'), so don't run individually through _renderListData(), instead
			 * build whatever carousel the data type uses, which will depend on data type.  Like simple image carousel,
			 * or MP3 player with playlist, etc.
			 */
			if ($params->get('fu_show_image_in_table', '0') == '2')
			{
				$id = $this->getHTMLId($id_num) . '_' . $id_num;
				$id_num++;
				$rendered = $this->buildCarousel($id, $data, $params, $thisRow);
			}
			else
			{
				for ($i = 0; $i < count($data); $i++)
				{
					$data[$i] = $this->_renderListData($data[$i], $thisRow, $i);
				}
			}
		}

		if ($params->get('fu_show_image_in_table', '0') != '2')
		{
			$data = json_encode($data);
			$rendered = parent::renderListData($data, $thisRow);
		}

		return $rendered;
	}

	/**
	 * Shows the data formatted for the CSV export view
	 *
	 * @param   string  $data      Element data
	 * @param   object  &$thisRow  All the data in the tables current row
	 *
	 * @return	string	Formatted value
	 */

	public function renderListData_csv($data, &$thisRow)
	{
		$data = FabrikWorker::JSONtoData($data, true);
		$params = $this->getParams();
		$format = $params->get('ul_export_encode_csv', 'base64');
		$raw = $this->getFullName(true, false) . '_raw';

		if ($params->get('ajax_upload') && $params->get('ajax_max', 4) == 1)
		{
			// Single ajax upload
			if (is_object($data))
			{
				$data = $data->file;
			}
			else
			{
				if ($data !== '')
				{
					$singleCropImg = FArrayHelper::getValue($data, 0);

					if (empty($singleCropImg))
					{
						$data = array();
					}
					else
					{
						$data = (array) $singleCropImg->file;
					}
				}
			}
		}

		foreach ($data as &$d)
		{
			$d = $this->encodeFile($d, $format);
		}

		// Fix \"" in json encoded string - csv clever enough to treat "" as a quote inside a "string value"
		$data = str_replace('\"', '"', $data);

		if ($this->isJoin())
		{
			// Multiple file uploads - raw data should be the file paths.
			$thisRow->$raw = json_encode($data);
		}
		else
		{
			$thisRow->$raw = str_replace('\"', '"', $thisRow->$raw);
		}

		return implode(GROUPSPLITTER, $data);
	}

	/**
	 * Shows the data formatted for the JSON export view
	 *
	 * @param   string  $data  file name
	 * @param   string  $rows  all the data in the tables current row
	 *
	 * @return	string	formatted value
	 */

	public function renderListData_json($data, $rows)
	{
		$data = explode(GROUPSPLITTER, $data);
		$params = $this->getParams();
		$format = $params->get('ul_export_encode_json', 'base64');

		foreach ($data as &$d)
		{
			$d = $this->encodeFile($d, $format);
		}

		return implode(GROUPSPLITTER, $data);
	}

	/**
	 * Encodes the file
	 *
	 * @param   string  $file    Relative file path
	 * @param   mixed   $format  Encode the file full|url|base64|raw|relative
	 *
	 * @return  string	Encoded file for export
	 */

	protected function encodeFile($file, $format = 'relative')
	{
		$path = JPATH_SITE . '/' . $file;

		if (!JFile::exists($path))
		{
			return $file;
		}

		switch ($format)
		{
			case 'full':
				return $path;
				break;
			case 'url':
				return COM_FABRIK_LIVESITE . str_replace('\\', '/', $file);
				break;
			case 'base64':
				return base64_encode(file_get_contents($path));
				break;
			case 'raw':
				return file_get_contents($path);
				break;
			case 'relative':
				return $file;
				break;
		}
	}

	/**
	 * Element plugin specific method for setting unecrypted values back into post data
	 *
	 * @param   array   &$post  Data passed by ref
	 * @param   string  $key    Key
	 * @param   string  $data   Elements unencrypted data
	 *
	 * @return  void
	 */

	public function setValuesFromEncryt(&$post, $key, $data)
	{
		if ($this->isJoin())
		{
			$data = FabrikWorker::JSONtoData($data, true);
		}

		parent::setValuesFromEncryt($post, $key, $data);
	}

	/**
	 * Called by form model to build an array of values to encrypt
	 *
	 * @param   array  &$values  Previously encrypted values
	 * @param   array  $data     Form data
	 * @param   int    $c        Repeat group counter
	 *
	 * @return  void
	 */

	public function getValuesToEncrypt(&$values, $data, $c)
	{
		$name = $this->getFullName(true, false);

		// Needs to be set to raw = false for fileupload
		$opts = array('raw' => false);
		$group = $this->getGroup();

		if ($group->canRepeat())
		{
			if (!array_key_exists($name, $values))
			{
				$values[$name]['data'] = array();
			}

			$values[$name]['data'][$c] = $this->getValue($data, $c, $opts);
		}
		else
		{
			$values[$name]['data'] = $this->getValue($data, $c, $opts);
		}
	}

	/**
	 * Examine the file being displayed and load in the corresponding
	 * class that deals with its display
	 *
	 * @param   string  $file  File
	 *
	 * @return  object  Element renderer
	 */

	protected function loadElement($file)
	{
		// $render loaded in required file.
		$render = null;
		$ext = String::strtolower(JFile::getExt($file));

		if (JFile::exists(JPATH_ROOT . '/plugins/fabrik_element/fileupload/element/custom/' . $ext . '.php'))
		{
			require JPATH_ROOT . '/plugins/fabrik_element/fileupload/element/custom/' . $ext . '.php';
		}
		elseif (JFile::exists(JPATH_ROOT . '/plugins/fabrik_element/fileupload/element/' . $ext . '.php'))
		{
			require JPATH_ROOT . '/plugins/fabrik_element/fileupload/element/' . $ext . '.php';
		}
		else
		{
			// Default down to allvideos content plugin
			if (in_array($ext, array('flv', '3gp', 'divx')))
			{
				require JPATH_ROOT . '/plugins/fabrik_element/fileupload/element/allvideos.php';
			}
			else
			{
				require JPATH_ROOT . '/plugins/fabrik_element/fileupload/element/default.php';
			}
		}

		return $render;
	}

	/**
	 * Display the file in the list
	 *
	 * @param   string  $data      Current cell data
	 * @param   array   &$thisRow  Current row data
	 * @param   int     $i         Repeat group count
	 *
	 * @return	string
	 */

	protected function _renderListData($data, &$thisRow, $i = 0)
	{
		$app = JFactory::getApplication();
		$package = $app->getUserState('com_fabrik.package', 'fabrik');
		$this->_repeatGroupCounter = $i;
		$params = $this->getParams();

		// $$$ hugh - added 'skip_check' param, as the exists() check in s3
		// storage adaptor can add a second or two per file, per row to table render time.
		$skip_exists_check = (int) $params->get('fileupload_skip_check', '0');

		if ($params->get('ajax_upload') && $params->get('ajax_max', 4) == 1)
		{
			// Not sure but after update from 2.1 to 3 for podion data was an object
			if (is_object($data))
			{
				$data = $data->file;
			}
			else
			{
				if ($data !== '')
				{
					$singleCropImg = json_decode($data);

					if (empty($singleCropImg))
					{
						$data = '';
					}
					else
					{
						$singleCropImg = $singleCropImg[0];
						$data = $singleCropImg->file;
					}
				}
			}
		}

		$data = FabrikWorker::JSONtoData($data);

		if (is_array($data) && !empty($data))
		{
			// Crop stuff needs to be removed from data to get correct file path
			$data = $data[0];
		}

		$storage = $this->getStorage();
		$use_download_script = $params->get('fu_use_download_script', '0');

		if ($use_download_script == FU_DOWNLOAD_SCRIPT_TABLE || $use_download_script == FU_DOWNLOAD_SCRIPT_BOTH)
		{
			if (empty($data) || !$storage->exists(COM_FABRIK_BASE . $data))
			{
				return '';
			}

			$canDownload = true;
			$aclEl = $this->getFormModel()->getElement($params->get('fu_download_acl', ''), true);

			if (!empty($aclEl))
			{
				$aclEl = $aclEl->getFullName();
				$aclElraw = $aclEl . '_raw';
				$user = JFactory::getUser();
				$groups = $user->getAuthorisedViewLevels();
				$canDownload = in_array($thisRow->$aclElraw, $groups);
			}

			$formModel = $this->getFormModel();
			$formid = $formModel->getId();
			$rowid = $thisRow->__pk_val;
			$elementid = $this->getId();
			$title = '';

			if ($params->get('fu_title_element') == '')
			{
				$title_name = $this->getFullName(true, false) . '__title';
			}
			else
			{
				$title_name = str_replace('.', '___', $params->get('fu_title_element'));
			}

			if (array_key_exists($title_name, $thisRow))
			{
				if (!empty($thisRow->$title_name))
				{
					$title = $thisRow->$title_name;
					$title = FabrikWorker::JSONtoData($title, true);
					$title = $title[$i];
				}
			}

			$downloadImg = $params->get('fu_download_access_image');

			$layout = $this->getLayout('downloadlink');
			$data = new stdClass;
			$data->canDownload = $canDownload;
			$data->title = $title;
			$data->noAccessImage = COM_FABRIK_LIVESITE . 'media/com_fabrik/images/' . $params->get('fu_download_noaccess_image');
			$data->downloadImg = ($downloadImg && JFile::exists('media/com_fabrik/images/' . $downloadImg)) ? COM_FABRIK_LIVESITE . 'media/com_fabrik/images/' . $downloadImg : '';
			$data->href =  COM_FABRIK_LIVESITE
				. 'index.php?option=com_' . $package . '&amp;task=plugin.pluginAjax&amp;plugin=fileupload&amp;method=ajax_download&amp;format=raw&amp;element_id='
				. $elementid . '&amp;formid=' . $formid . '&amp;rowid=' . $rowid . '&amp;repeatcount=' . $i;;

			return $layout->render($data);
		}

		if ($params->get('fu_show_image_in_table') == '0')
		{
			$render = $this->loadElement('default');
		}
		else
		{
			$render = $this->loadElement($data);
		}

		if (empty($data) || (!$skip_exists_check && !$storage->exists($data)))
		{
			$render->output = '';
		}
		else
		{
			$render->renderListData($this, $params, $data, $thisRow);
		}

		if ($render->output == '' && $params->get('default_image') != '')
		{
			$defaultURL = $storage->getFileUrl(str_replace(COM_FABRIK_BASE, '', $params->get('default_image')));
			$render->output = '<img src="' . $defaultURL . '" alt="image" />';
		}
		else
		{
			/*
			 * If a static 'icon file' has been specified, we need to call the main
			 * element model replaceWithIcons() to make it happen.
			 */
			if ($params->get('icon_file', '') !== '')
			{
				$listModel = $this->getListModel();
				$render->output = $this->replaceWithIcons($render->output, 'list', $listModel->getTmpl());
			}
		}

		return $render->output;
	}

	/**
	 * Do we need to include the lightbox js code
	 *
	 * @return	bool
	 */

	public function requiresLightBox()
	{
		return true;
	}

	/**
	 * Do we need to include the slideshow js code
	 *
	 * @return	bool
	 */

	public function requiresSlideshow()
	{
		/*
		 * $$$ - testing slideshow, @TODO finish this!  Check for view type
		 */
		$params = $this->getParams();

		return $params->get('fu_show_image_in_table', '0') === '2' || $params->get('fu_show_image', '0') === '3';
	}

	/**
	 * Manipulates posted form data for insertion into database
	 *
	 * @param   mixed  $val   This elements posted form data
	 * @param   array  $data  Posted form data
	 *
	 * @return  mixed
	 */

	public function storeDatabaseFormat($val, $data)
	{
		// Val already contains group splitter from processUpload() code
		return $val;
	}

	/**
	 * Checks the posted form data against elements INTERNAL validation rule
	 * e.g. file upload size / type
	 *
	 * @param   string  $data           Elements data
	 * @param   int     $repeatCounter  Repeat group counter
	 *
	 * @return  bool	True if passes / false if fails validation
	 */

	public function validate($data = array(), $repeatCounter = 0)
	{
		$app = JFactory::getApplication();
		$input = $app->input;
		$params = $this->getParams();
		$this->_validationErr = '';
		$errors = array();

		$name = $this->getFullName(true, false);
		$ok = true;
		$files = $input->files->get($name, array(), 'cmd');

		if (array_key_exists($repeatCounter, $files))
		{
			$file = FArrayHelper::getValue($files, $repeatCounter);
		}
		else
		{
			// Single upload
			$file = $files;
		}

		// Perhaps an ajax upload? In any event $file empty was giving errors with upload element in multipage form.
		if (!array_key_exists('name', $file))
		{
			return true;
		}

		$fileName = $file['name'];
		$fileSize = $file['size'];

		if (!$this->_fileUploadFileTypeOK($fileName))
		{
			$errors[] = FText::_('PLG_ELEMENT_FILEUPLOAD_FILE_TYPE_NOT_ALLOWED');
			$ok = false;
		}

		if (!$this->_fileUploadSizeOK($fileSize))
		{
			$ok = false;
			$size = $fileSize / 1000;
			$errors[] = JText::sprintf('PLG_ELEMENT_FILEUPLOAD_FILE_TOO_LARGE', $params->get('ul_max_file_size'), $size);
		}

		/**
		 * @FIXME - need to check for Amazon S3 storage?
		 */
		$filepath = $this->_getFilePath($repeatCounter);
		jimport('joomla.filesystem.file');

		if (JFile::exists($filepath))
		{
			if ($params->get('ul_file_increment', 0) == 0)
			{
				$errors[] = FText::_('PLG_ELEMENT_FILEUPLOAD_EXISTING_FILE_NAME');
				$ok = false;
			}
		}

		$this->validationError = implode('<br />', $errors);

		return $ok;
	}

	/**
	 * Get an array of allowed file extensions
	 *
	 * @return array
	 */

	protected function _getAllowedExtension()
	{
		$params = $this->getParams();
		$allowedFiles = $params->get('ul_file_types');

		if ($allowedFiles != '')
		{
			// $$$ hugh - strip spaces and leading ., as folk often do ".bmp, .jpg"
			// preg_replace('#(\s*|^)\.?#', '', trim($allowedFiles));
			$allowedFiles = str_replace(' ', '', $allowedFiles);
			$allowedFiles = str_replace('.', '', $allowedFiles);
			$aFileTypes = explode(",", $allowedFiles);
		}
		else
		{
			$mediaparams = JComponentHelper::getParams('com_media');
			$aFileTypes = explode(',', $mediaparams->get('upload_extensions'));
		}

		return $aFileTypes;
	}

	/**
	 * This checks the uploaded file type against the csv specified in the upload
	 * element
	 *
	 * @param   string  $myFileName  Filename
	 *
	 * @return	bool	True if upload file type ok
	 */

	protected function _fileUploadFileTypeOK($myFileName)
	{
		$aFileTypes = $this->_getAllowedExtension();

		if ($myFileName == '')
		{
			return true;
		}

		$curr_f_ext = String::strtolower(JFile::getExt($myFileName));
		array_walk($aFileTypes, create_function('&$v', '$v = String::strtolower($v);'));

		return in_array($curr_f_ext, $aFileTypes);
	}

	/**
	 * This checks that the fileupload size is not greater than that specified in
	 * the upload element
	 *
	 * @param   string  $myFileSize  File size
	 *
	 * @return	bool	True if upload file type ok
	 */

	protected function _fileUploadSizeOK($myFileSize)
	{
		$params = $this->getParams();
		$max_size = $params->get('ul_max_file_size') * 1000;

		if ($myFileSize <= $max_size)
		{
			return true;
		}

		return false;
	}

	/**
	 * if we are using plupload but not with crop
	 *
	 * @param   string  $name  Element
	 *
	 * @return	bool	If processed or not
	 */

	protected function processAjaxUploads($name)
	{
		$app = JFactory::getApplication();
		$input = $app->input;
		$params = $this->getParams();

		if ($this->canCrop() == false && $input->get('task') !== 'pluginAjax' && $params->get('ajax_upload') == true)
		{
			$filter = JFilterInput::getInstance();
			$post = $filter->clean($_POST, 'array');
			$raw = $this->getValue($post);

			if ($raw == '')
			{
				return true;
			}

			if (empty($raw))
			{
				return true;
			}
			// $$$ hugh - for some reason, we're now getting $raw[] with a single, uninitialized entry back
			// from getvalue() when no files are uploaded
			if (count($raw) == 1 && array_key_exists(0, $raw) && empty($raw[0]))
			{
				return true;
			}

			$crop = (array) FArrayHelper::getValue($raw, 'crop');
			$ids = (array) FArrayHelper::getValue($raw, 'id');
			$ids = array_values($ids);

			$saveParams = array();
			$files = array_keys($crop);
			$groupModel = $this->getGroup();
			$formModel = $this->getFormModel();
			$isjoin = ($groupModel->isJoin() || $this->isJoin());

			if ($isjoin)
			{
				if (!$groupModel->canRepeat() && !$this->isJoin())
				{
					$files = $files[0];
				}

				$joinid = $groupModel->getGroup()->join_id;

				if ($this->isJoin())
				{
					$joinid = $this->getJoinModel()->getJoin()->id;
				}

				$j = $this->getJoinModel()->getJoin()->table_join;
				$joinsid = $j . '___id';
				$joinsparam = $j . '___params';

				$name = $this->getFullName(true, false);

				$formModel->updateFormData($name, $files, true);
				$formModel->updateFormData($joinsid, $ids, true);
				$formModel->updateFormData($joinsparam, $saveParams, true);
			}
			else
			{
				// Only one file
				$store = array();

				for ($i = 0; $i < count($files); $i++)
				{
					$o = new stdClass;
					$o->file = $files[$i];
					$o->params = $crop[$files[$i]];
					$store[] = $o;
				}

				$store = json_encode($store);
				$formModel->updateFormData($name . '_raw', $store);
				$formModel->updateFormData($name, $store);
			}

			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * If an image has been uploaded with ajax upload then we may need to crop it
	 * Since 3.0.7 crop data is posted as base64 encoded info from the actual canvas element - much simpler and more accurate cropping
	 *
	 * @param   string  $name  Element
	 *
	 * @return	bool	If processed or not
	 */

	protected function crop($name)
	{
		$app = JFactory::getApplication();
		$input = $app->input;
		$params = $this->getParams();

		if ($this->canCrop() == true && $input->get('task') !== 'pluginAjax')
		{
			$filter = JFilterInput::getInstance();
			$post = $filter->clean($_POST, 'array');
			$raw = FArrayHelper::getValue($post, $name . '_raw', array());

			if (!$this->canUse())
			{
				// Ensure readonly elements not overwritten
				return true;
			}

			if ($this->getValue($post) != 'Array,Array')
			{
				$raw = $this->getValue($post);

				// $$$ rob 26/07/2012 inline edit producing a string value for $raw on save
				if ($raw == '' || empty($raw) || is_string($raw))
				{
					return true;
				}

				if (array_key_exists(0, $raw))
				{
					$crop = (array) FArrayHelper::getValue($raw[0], 'crop');
					$ids = (array) FArrayHelper::getValue($raw[0], 'id');
					$cropData = (array) FArrayHelper::getValue($raw[0], 'cropdata');
				}
				else
				{
					// Single uploaded image.
					$crop = (array) FArrayHelper::getValue($raw, 'crop');
					$ids = (array) FArrayHelper::getValue($raw, 'id');
					$cropData = (array) FArrayHelper::getValue($raw, 'cropdata');
				}
			}
			else
			{
				// Single image
				$crop = (array) FArrayHelper::getValue($raw, 'crop');
				$ids = (array) FArrayHelper::getValue($raw, 'id');
				$cropData = (array) FArrayHelper::getValue($raw, 'cropdata');
			}

			if ($raw == '')
			{
				return true;
			}

			$ids = array_values($ids);
			$saveParams = array();
			$files = array_keys($crop);
			$storage = $this->getStorage();
			$oImage = FabimageHelper::loadLib($params->get('image_library'));
			$oImage->setStorage($storage);
			$fileCounter = 0;

			foreach ($crop as $filepath => $json)
			{
				$imgData = $cropData[$filepath];
				$imgData = substr($imgData, strpos($imgData, ',') + 1);

				// Need to decode before saving since the data we received is already base64 encoded
				$imgData = base64_decode($imgData);

				$coords = json_decode(urldecode($json));
				$saveParams[] = $json;

				// @todo allow uploading into front end designated folders?
				$myFileDir = '';
				$cropPath = $storage->clean(JPATH_SITE . '/' . $params->get('fileupload_crop_dir') . '/' . $myFileDir . '/', false);
				$w = new FabrikWorker;
				$cropPath = $w->parseMessageForPlaceHolder($cropPath);

				if ($cropPath != '')
				{
					if (!$storage->folderExists($cropPath))
					{
						if (!$storage->createFolder($cropPath))
						{
							$this->setError(21, "Could not make dir $cropPath ");
							continue;
						}
					}
				}

				$filepath = $storage->clean(JPATH_SITE . '/' . $filepath);
				$fileURL = $storage->getFileUrl(str_replace(COM_FABRIK_BASE, '', $filepath));
				$destCropFile = $storage->_getCropped($fileURL);
				$destCropFile = $storage->urlToPath($destCropFile);
				$destCropFile = $storage->clean($destCropFile);

				if (!JFile::exists($filepath))
				{
					unset($files[$fileCounter]);
					$fileCounter++;
					continue;
				}

				$fileCounter++;

				if ($imgData != '')
				{
					if (!$storage->write($destCropFile, $imgData))
					{
						throw new RuntimeException('Couldn\'t write image, ' . $destCropFile, 500);
					}
				}

				$storage->setPermissions($destCropFile);
			}

			$groupModel = $this->getGroup();
			$isjoin = ($groupModel->isJoin() || $this->isJoin());
			$formModel = $this->getFormModel();

			if ($isjoin)
			{
				if (!$groupModel->canRepeat() && !$this->isJoin())
				{
					$files = $files[0];
				}

				$joinid = $groupModel->getGroup()->join_id;

				if ($this->isJoin())
				{
					$joinid = $this->getJoinModel()->getJoin()->id;
				}

				$name = $this->getFullName(true, false);

				if ($groupModel->isJoin())
				{
					$j = $this->getJoinModel()->getJoin()->table_join;
				}
				else
				{
					$j = $name;
				}

				$joinsid = $j . '___id';
				$joinsparam = $j . '___params';

				$formModel->updateFormData($name, $files);
				$formModel->updateFormData($name . '_raw', $files);

				$formModel->updateFormData($joinsid, $ids);
				$formModel->updateFormData($joinsid . '_raw', $ids);

				$formModel->updateFormData($joinsparam, $saveParams);
				$formModel->updateFormData($joinsparam . '_raw', $saveParams);
			}
			else
			{
				// Only one file
				$store = array();

				for ($i = 0; $i < count($files); $i++)
				{
					$o = new stdClass;
					$o->file = $files[$i];
					$o->params = $saveParams[$i];
					$store[] = $o;
				}

				$store = json_encode($store);
				$formModel->updateFormData($name . '_raw', $store);
				$formModel->updateFormData($name, $store);
			}

			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * OPTIONAL
	 *
	 * @return  void
	 */

	public function processUpload()
	{
		$app = JFactory::getApplication();
		$input = $app->input;
		$params = $this->getParams();
		$groupModel = $this->getGroup();
		$formModel = $this->getFormModel();
		$origData = $formModel->getOrigData();
		$name = $this->getFullName(true, false);
		$myFileDirs = $input->get($name, array(), 'array');

		if (!$this->canUse())
		{
			// If the user can't use the plugin no point processing an non-existant upload
			return;
		}

		if ($this->processAjaxUploads($name))
		{
			// Stops form data being updated with blank data.
			return;
		}

		if ($input->getInt('fabrik_ajax') == 1)
		{
			// Inline edit for example no $_FILE data sent
			return;
		}
		/* If we've turned on crop but not set ajax upload then the cropping wont work so we shouldn't return
		 * otherwise no standard image processed
		 */
		if ($this->crop($name) && $params->get('ajax_upload'))
		{
			// Stops form data being updated with blank data.
			return;
		}

		$files = array();
		$deletedImages = $input->get('fabrik_fileupload_deletedfile', array(), 'array');
		$gid = $groupModel->getId();

		$deletedImages = FArrayHelper::getValue($deletedImages, $gid, array());
		$imagesToKeep = array();

		for ($j = 0; $j < count($origData); $j++)
		{
			foreach ($origData[$j] as $key => $val)
			{
				if ($key == $name && !empty($val))
				{
					if (in_array($val, $deletedImages))
					{
						unset($origData[$j]->$key);
					}
					else
					{
						$imagesToKeep[$j] = $origData[$j]->$key;
					}

					break;
				}
			}
		}

		$fdata = $_FILES[$name]['name'];
		/*
		 * $$$ hugh - monkey patch to get simple upload working again after this commit:
		 * https://github.com/Fabrik/fabrik/commit/5970a1845929c494c193b9227c32c983ff30fede
		 * I don't think $fdata is ever going to be an array, after the above changes, but for now
		 * I'm just patching round it.  Rob will fix it properly with his hammer.  :)
		 * UPDATE - yes, it will be an array, if we have a repeat group with simple uploads.
		 * Continuing to hack around with this!
		 */
		if (is_array($fdata))
		{
			foreach ($fdata as $i => $f)
			{
				$myFileDir = FArrayHelper::getValue($myFileDirs, $i, '');
				$file = array('name' => $_FILES[$name]['name'][$i],
						'type' => $_FILES[$name]['type'][$i],
						'tmp_name' => $_FILES[$name]['tmp_name'][$i],
						'error' => $_FILES[$name]['error'][$i],
						'size' => $_FILES[$name]['size'][$i]);

				if ($file['name'] != '')
				{
					$files[$i] = $this->_processIndUpload($file, $myFileDir, $i);
				}
				else
				{
					if (array_key_exists($i, $imagesToKeep))
					{
						$files[$i] = $imagesToKeep[$i];
					}
				}
			}

			foreach ($imagesToKeep as $k => $v)
			{
				if (!array_key_exists($k, $files))
				{
					$files[$k] = $v;
				}
			}

			foreach ($files as &$f)
			{
				$f = str_replace('\\', '/', $f);
			}

			if ($params->get('upload_delete_image', false))
			{
				foreach ($deletedImages as $filename)
				{
					$this->deleteFile($filename);
				}
			}

			$formModel->updateFormData($name . '_raw', $files);
			$formModel->updateFormData($name, $files);
		}
		else
		{
			$myFileDir = FArrayHelper::getValue($myFileDirs, 0, '');
			$file = array('name' => $_FILES[$name]['name'],
					'type' => $_FILES[$name]['type'],
					'tmp_name' => $_FILES[$name]['tmp_name'],
					'error' => $_FILES[$name]['error'],
					'size' => $_FILES[$name]['size']);

			if ($file['name'] != '')
			{
				$files[0] = $this->_processIndUpload($file, $myFileDir);
			}
			else
			{
				$files[0] = FArrayHelper::getValue($imagesToKeep, 0, '');
			}

			foreach ($imagesToKeep as $k => $v)
			{
				if (!array_key_exists($k, $files))
				{
					$files[$k] = $v;
				}
			}

			foreach ($files as &$f)
			{
				$f = str_replace('\\', '/', $f);
			}

			if ($params->get('upload_delete_image', false))
			{
				foreach ($deletedImages as $filename)
				{
					$this->deleteFile($filename);
				}
			}
			/*
			 * Update form model with file data
			 *
			 * $$$ hugh - another monkey patch just to get simple upload going again
			* We don't ever want to actually end up with the old GROUPSPLITTER arrangement,
			* but if we've got repeat groups on the form, we'll have multiple entries in
			* $files for the same single, simple upload.  So boil it down with an array_unique()
			* HORRIBLE hack .. really need to fix this whole chunk of code.
			*/
			/*
			$formModel->updateFormData($name . '_raw', $files);
			$formModel->updateFormData($name, $files);
			*/
			$files = array_unique($files);
			$strfiles = implode(GROUPSPLITTER, $files);
			$formModel->updateFormData($name . '_raw', $strfiles);
			$formModel->updateFormData($name, $strfiles);
		}
	}

	/**
	 * Delete the file
	 *
	 * @param   string  $filename  Path to file (not including JPATH)
	 *
	 * @return  void
	 */

	protected function deleteFile($filename)
	{
		$storage = $this->getStorage();
		$user = JFactory::getUser();
		$file = $storage->clean(JPATH_SITE . '/' . $filename);
		$thumb = $storage->clean($storage->_getThumb($filename));
		$cropped = $storage->clean($storage->_getCropped($filename));

		$logMsg = 'Delete files: ' . $file . ' , ' . $thumb . ', ' . $cropped . '; user = ' . $user->get('id');
		JLog::add($logMsg, JLog::WARNING, 'com_fabrik.element.fileupload');

		if ($storage->exists($file))
		{
			$storage->delete($file);
		}

		if ($storage->exists($thumb))
		{
			$storage->delete($thumb);
		}
		else
		{
			if ($storage->exists(JPATH_SITE . '/' . $thumb))
			{
				$storage->delete(JPATH_SITE . '/' . $thumb);
			}
		}

		if ($storage->exists($cropped))
		{
			$storage->delete($cropped);
		}
		else
		{
			if ($storage->exists(JPATH_SITE . '/' . $cropped))
			{
				$storage->delete(JPATH_SITE . '/' . $cropped);
			}
		}
	}

	/**
	 * Does the element consider the data to be empty
	 * Used in isempty validation rule
	 *
	 * @param   array  $data           Data to test against
	 * @param   int    $repeatCounter  Repeat group #
	 *
	 * @return  bool
	 */

	public function dataConsideredEmpty($data, $repeatCounter)
	{
		$app = JFactory::getApplication();
		$params = $this->getParams();
		$input = $app->input;

		if ($input->get('rowid', '') !== '')
		{
			if ($input->get('task') == '')
			{
				return parent::dataConsideredEmpty($data, $repeatCounter);
			}

			$olddaata = FArrayHelper::getValue($this->getFormModel()->_origData, $repeatCounter);

			if (!is_null($olddaata))
			{
				$name = $this->getFullName(true, false);
				$aoldData = ArrayHelper::fromObject($olddaata);
				$r = FArrayHelper::getValue($aoldData, $name, '') === '' ? true : false;

				if (!$r)
				{
					/* If an original value is found then data not empty - if not found continue to check the $_FILES array to see if one
					 * has been uploaded
					 */
					return false;
				}
			}
		}
		else
		{
			if ($input->get('task') == '')
			{
				return parent::dataConsideredEmpty($data, $repeatCounter);
			}
		}

		$groupModel = $this->getGroup();

		if ($groupModel->isJoin())
		{
			$name = $this->getFullName(true, false);
			$files = $input->files->get($name, array(), 'raw');

			if ($groupModel->canRepeat())
			{
				$file = empty($files) ? '' : $files[$repeatCounter]['name'];
			}
			else
			{
				//$file = $joindata[$joinid][$name]['name'];
				$file = $files['name'];
			}

			return $file == '' ? true : false;
		}
		else
		{
			$name = $this->getFullName(true, false);

			if ($this->isJoin())
			{
				$join = $this->getJoinModel()->getJoin();
				$joinid = $join->id;
				$joindata = $input->post->get('join', array(), 'array');
				$joindata = FArrayHelper::getValue($joindata, $joinid, array());
				$joindata = FArrayHelper::getValue($joindata, $name, array());
				$joinids = FArrayHelper::getValue($joindata, 'id', array());

				return empty($joinids) ? true : false;
			}
			else
			{
				// Single ajax upload
				if ($params->get('ajax_upload'))
				{
					$d = (array) $input->get($name, array(), 'array');

					if (array_key_exists('id', $d))
					{
						return false;
					}
				}
				else
				{
					$files = $input->files->get($name, array(), 'raw');
					$file = ArrayHelper::getValue($files, 'name', '');

					return $file == '' ? true : false;
				}
			}
		}

		if (empty($file))
		{
			$file = $input->get($name);

			// Ajax test - nothing in files
			return $file == '' ? true : false;
		}

		// No files selected?
		return $file['name'] == '' ? true : false;
	}

	/**
	 * Process the upload (can be called via ajax from pluploader)
	 *
	 * @param   array   &$file               File info
	 * @param   string  $myFileDir           User selected upload folder
	 * @param   int     $repeatGroupCounter  Repeat group counter
	 *
	 * @return	string	Location of uploaded file
	 */

	protected function _processIndUpload(&$file, $myFileDir = '', $repeatGroupCounter = 0)
	{
		$params = $this->getParams();
		$user = JFactory::getUser();
		$storage = $this->getStorage();

		// $$$ hugh - check if we need to blow away the cached filepath, set in validation
		$myFileName = $storage->cleanName($file['name'], $repeatGroupCounter);

		if ($myFileName != $file['name'])
		{
			$file['name'] = $myFileName;
			unset($this->_filePaths[$repeatGroupCounter]);
		}

		$tmpFile = $file['tmp_name'];
		$uploader = $this->getFormModel()->getUploader();

		if ($params->get('ul_file_types') == '')
		{
			$params->set('ul_file_types', implode(',', $this->_getAllowedExtension()));
		}

		$err = null;

		// Set FTP credentials, if given
		jimport('joomla.client.helper');
		JClientHelper::setCredentialsFromRequest('ftp');

		if ($myFileName == '')
		{
			return;
		}

		$filepath = $this->_getFilePath($repeatGroupCounter);

		if (!FabrikUploader::canUpload($file, $err, $params))
		{
			$this->setError(100, $file['name'] . ': ' . FText::_($err));
		}

		if ($storage->exists($filepath))
		{
			switch ($params->get('ul_file_increment', 0))
			{
				case 0:
					break;
				case 1:
					$filepath = FabrikUploader::incrementFileName($filepath, $filepath, 1);
					break;
				case 2:
					JLog::add('Ind upload Delete file: ' . $filepath . '; user = ' . $user->get('id'), JLog::WARNING, 'com_fabrik.element.fileupload');
					$storage->delete($filepath);
					break;
			}
		}

		if (!$storage->upload($tmpFile, $filepath))
		{
			$uploader->moveError = true;
			$this->setError(100, JText::sprintf('PLG_ELEMENT_FILEUPLOAD_UPLOAD_ERR', $tmpFile, $filepath));

			return;
		}

		$filepath = $storage->getUploadedFilePath();
		jimport('joomla.filesystem.path');
		$storage->setPermissions($filepath);

		// $$$ hugh @TODO - shouldn't we check to see if it's actually an image before we do any of this stuff???

		// Resize main image
		$oImage = FabimageHelper::loadLib($params->get('image_library'));
		$oImage->setStorage($storage);
		$mainWidth = $params->get('fu_main_max_width', '');
		$mainHeight = $params->get('fu_main_max_height', '');

		if ($mainWidth != '' || $mainHeight != '')
		{
			// $$$ rob ensure that both values are integers otherwise resize fails
			if ($mainHeight == '')
			{
				$mainHeight = (int) $mainWidth;
			}

			if ($mainWidth == '')
			{
				$mainWidth = (int) $mainHeight;
			}

			$oImage->resize($mainWidth, $mainHeight, $filepath, $filepath);
		}
		// $$$ hugh - if it's a PDF, make sure option is set to attempt PDF thumb
		$make_thumbnail = $params->get('make_thumbnail') == '1' ? true : false;

		if (JFile::getExt($filepath) == 'pdf' && $params->get('fu_make_pdf_thumb', '0') == '0')
		{
			$make_thumbnail = false;
		}

		if ($make_thumbnail)
		{
			$thumbPath = $storage->clean(JPATH_SITE . '/' . $params->get('thumb_dir') . '/' . $myFileDir . '/', false);
			$w = new FabrikWorker;
			$thumbPath = $w->parseMessageForPlaceHolder($thumbPath);
			$thumbPrefix = $params->get('thumb_prefix');
			$maxWidth = $params->get('thumb_max_width', 125);
			$maxHeight = $params->get('thumb_max_height', 125);

			if ($thumbPath != '')
			{
				if (!$storage->folderExists($thumbPath))
				{
					if (!$storage->createFolder($thumbPath))
					{
						throw new RuntimeException("Could not make dir $thumbPath");
					}
				}
			}

			$fileURL = $storage->getFileUrl(str_replace(COM_FABRIK_BASE, '', $filepath));
			$destThumbFile = $storage->_getThumb($fileURL);
			$destThumbFile = $storage->urlToPath($destThumbFile);
			$oImage->resize($maxWidth, $maxHeight, $filepath, $destThumbFile);
			$storage->setPermissions($destThumbFile);
		}

		$storage->setPermissions($filepath);
		$storage->finalFilePathParse($filepath);

		return $filepath;
	}

	/**
	 * Get the file storage object amazon s3/filesystem
	 *
	 * @return object
	 */

	public function getStorage()
	{
		if (!isset($this->storage))
		{
			$params = $this->getParams();
			$storageType = JFilterInput::getInstance()->clean($params->get('fileupload_storage_type', 'filesystemstorage'), 'CMD');
			require_once JPATH_ROOT . '/plugins/fabrik_element/fileupload/adaptors/' . $storageType . '.php';
			$storageClass = String::ucfirst($storageType);
			$this->storage = new $storageClass($params);
		}

		return $this->storage;
	}

	/**
	 * Get the full server file path for the upload, including the file name
	 *
	 * @param   int  $repeatCounter  Repeat group counter
	 *
	 * @return	string	Path
	 */

	protected function _getFilePath($repeatCounter = 0)
	{
		$params = $this->getParams();

		if (!isset($this->_filePaths))
		{
			$this->_filePaths = array();
		}

		if (array_key_exists($repeatCounter, $this->_filePaths))
		{
			/*
			 * $$$ hugh - if it uses element placeholders, there's a likelihood the element
			 * data may have changed since we cached the path during validation, so we need
			 * to rebuild it.  For instance, if the element data is changed by a onBeforeProcess
			 * submission plugin, or by a 'replace' validation.
			 */
			if (!FabrikString::usesElementPlaceholders($params->get('ul_directory')))
			{
				return $this->_filePaths[$repeatCounter];
			}
		}

		$filter = JFilterInput::getInstance();
		$aData = $filter->clean($_POST, 'array');
		$elName = $this->getFullName(true, false);
		$elNameRaw = $elName . '_raw';

		// @TODO test with fileuploads in join groups
		$groupModel = $this->getGroup();

		/**
		 * $$$ hugh - if we use the @ way of doing this, and one of the array keys doesn't exist,
		 * PHP still sets an error, even though it doesn't toss it.  So if we then have some eval'd
		 * code, like a PHP validation, and do the logError() thing, that will pick up and report this error,
		 * and fail the validation.  Which is VERY hard to track.  So we'll have to do it long hand.
		 */
		// $myFileName = array_key_exists($elName, $_FILES) ? @$_FILES[$elName]['name'] : @$_FILES['file']['name'];
		$myFileName = '';

		if (array_key_exists($elName, $_FILES) && is_array($_FILES[$elName]))
		{
			$myFileName = FArrayHelper::getValue($_FILES[$elName], 'name', '');
		}
		else
		{
			if (array_key_exists('file', $_FILES) && is_array($_FILES['file']))
			{
				$myFileName = FArrayHelper::getValue($_FILES['file'], 'name', '');
			}
		}

		if (is_array($myFileName))
		{
			$myFileName = FArrayHelper::getValue($myFileName, $repeatCounter, '');
		}

		$myFileDir = array_key_exists($elNameRaw, $aData) && is_array($aData[$elNameRaw]) ? @$aData[$elNameRaw]['ul_end_dir'] : '';

		if (is_array($myFileDir))
		{
			$myFileDir = FArrayHelper::getValue($myFileDir, $repeatCounter, '');
		}

		$storage = $this->getStorage();

		// $$$ hugh - check if we need to blow away the cached filepath, set in validation
		$myFileName = $storage->cleanName($myFileName, $repeatCounter);

		$folder = $params->get('ul_directory');
		$folder = $folder . '/' . $myFileDir;

		if ($storage->appendServerPath())
		{
			$folder = JPATH_SITE . '/' . $folder;
		}

		$folder = JPath::clean($folder);
		$w = new FabrikWorker;
		
		/**
		 * $$$ hugh - if the path uses placeholders, we need to work out if this element is in a repeat group,
		 * and if it uses placeholders from within it's repeat instance, and if so, only use "this" repeat's
		 * values, not the comma separated list of all repeat value we get from parseMessageForPlaceholder().
		 * 
		 * NOTE - @FIXME - no point trying to do this if no formData, which will be a problem for AJAX based deletions,
		 * as this function gets called from onAjax_deleteFile(), but we already know using placeholders in AJAX-ified
		 * uploads doesn't work anyway.  But might as well flag it with an 
		 */
		if (strstr($folder, '{') && isset($formModel->formData))
		{
			$groupModel = $this->getGroupModel();
			if ($groupModel->canRepeat())
			{
				$elementModels = $groupModel->getPublishedElements();
				$formModel = $this->getFormModel();
				
				foreach ($elementModels as $elementModel)
				{
					$repeatElName = $elementModel->getFullName(true, false);
					foreach (array($repeatElName, repeatElName . '_raw') as $tmpElName)
					{
						if (strstr($folder, '{'.$tmpElName.'}'))
						{
							if (array_key_exists($tmpElName, $formModel->formData) && is_array($formModel->formData[$tmpElName]) && array_key_exists($repeatCounter, $formModel->formData[$tmpElName]))
							{
								$tmpVal = $formModel->formData[$tmpElName][$repeatCounter];
								$folder = str_replace('{'.$tmpElname.'}', $tmpVal, $folder);
							}
						}
					}
				}
			}
		}
		
		$folder = $w->parseMessageForPlaceHolder($folder);

		if ($storage->appendServerPath())
		{
			JPath::check($folder);
		}

		$storage->makeRecursiveFolders($folder);
		$p = $folder . '/' . $myFileName;
		$this->_filePaths[$repeatCounter] = JPath::clean($p);

		return $this->_filePaths[$repeatCounter];
	}

	/**
	 * Draws the html form element
	 *
	 * @param   array  $data           To pre-populate element with
	 * @param   int    $repeatCounter  Repeat group counter
	 *
	 * @return  string	Elements html
	 */

	public function render($data, $repeatCounter = 0)
	{
		$this->_repeatGroupCounter = $repeatCounter;
		$id = $this->getHTMLId($repeatCounter);
		$name = $this->getHTMLName($repeatCounter);
		$groupModel = $this->getGroup();
		$element = $this->getElement();
		$params = $this->getParams();

		$use_wip = $params->get('upload_use_wip', '0') == '1';
		$device_capture = $params->get('ul_device_capture', '0');

		if ($element->hidden == '1')
		{
			return $this->getHiddenField($name, $data[$name], $id);
		}

		$str = array();
		$value = $this->getValue($data, $repeatCounter);
		$value = is_array($value) ? $value : FabrikWorker::JSONtoData($value, true);
		$value = $this->checkForSingleCropValue($value);

		if ($params->get('ajax_upload'))
		{
			if (isset($value->file))
			{
				$value = $value->file;
			}
		}

		$storage = $this->getStorage();
		$use_download_script = $params->get('fu_use_download_script', '0');

		// $$$ rob - explode as it may be grouped data (if element is a repeating upload)
		$values = is_array($value) ? $value : FabrikWorker::JSONtoData($value, true);

		if (!$this->isEditable() && ($use_download_script == FU_DOWNLOAD_SCRIPT_DETAIL || $use_download_script == FU_DOWNLOAD_SCRIPT_BOTH))
		{
			$links = array();

			if (!is_array($value))
			{
				$value = (array) $value;
			}

			foreach ($value as $v)
			{
				$links[] = $this->downloadLink($v, $data, $repeatCounter);
			}

			return count($links) < 2 ? implode("\n", $links) : '<ul class="fabrikRepeatData"><li>' . implode('</li><li>', $links) . '</li></ul>';
		}

		$render = new stdClass;
		$render->output = '';
		$allRenders = array();

		/*
		 * $$$ hugh testing slideshow display
		 */
		if ($params->get('fu_show_image') === '3' && !$this->isEditable())
		{
			// Failed validations - format different!
			if (array_key_exists('id', $values))
			{
				$values = array_keys($values['id']);
			}

			$rendered = $this->buildCarousel($id, $values, $params, $data);

			return $rendered;
		}

		if (($params->get('fu_show_image') !== '0' && !$params->get('ajax_upload')) || !$this->isEditable())
		{
			// Failed validations - format different!
			if (array_key_exists('id', $values))
			{
				$values = array_keys($values['id']);
			}

			// End failed validations
			foreach ($values as $value)
			{
				if (is_object($value))
				{
					$value = $value->file;
				}

				$render = $this->loadElement($value);

				if (
					($use_wip && $this->isEditable())
					|| (
						$value != ''
						&& (
							$storage->exists(COM_FABRIK_BASE . $value)
							|| String::substr($value, 0, 4) == 'http')
						)
					)
				{
					$render->render($this, $params, $value);
				}

				if ($render->output != '')
				{
					if ($this->isEditable())
					{
						// $$$ hugh - TESTING - using HTML5 to show a selected image, so if no file, still need the span, hidden, but not the actual delete button
						if ($use_wip && empty($value))
						{
							$render->output = '<span class="fabrikUploadDelete fabrikHide" id="' . $id . '_delete_span">' . $render->output . '</span>';
						}
						else
						{
							$render->output = '<span class="fabrikUploadDelete" id="' . $id . '_delete_span">' . $this->deleteButton($value) . $render->output . '</span>';
						}
					}

					$allRenders[] = $render->output;
				}
			}
		}

		if (!$this->isEditable())
		{
			if ($render->output == '' && $params->get('default_image') != '')
			{
				$render->output = '<img src="' . $params->get('default_image') . '" alt="image" />';
				$allRenders[] = $render->output;
			}

			$str[] = '<div class="fabrikSubElementContainer">';
			$ul = '<ul class="fabrikRepeatData"><li>' . implode('</li><li>', $allRenders) . '</li></ul>';
			$str[] = count($allRenders) < 2 ? implode("\n", $allRenders) : $ul;
			$str[] = '</div>';

			return implode("\n", $str);
		}

		$allRenders = implode('<br/>', $allRenders);
		$allRenders .= ($allRenders == '') ? '' : '<br/>';
		$capture = "";
		switch ($device_capture)
		{
			case 1:
				$capture = ' capture="camera"';
			case 2:
				$capture = ' accept="image/*"' . $capture;
				break;
			case 3:
				$capture = ' capture="microphone"';
			case 4:
				$capture = ' accept="audio/*"' . $capture;
				break;
			case 5:
				$capture = ' capture="camcorder"';
			case 6:
				$capture = ' accept="video/*"' . $capture;
				break;
			default:
				$capture = implode(",.",$this->_getAllowedExtension());
				$capture = $capture ? ' accept=".' . $capture . '"' : '';
				break;
		}

		$str[] = $allRenders . '<input class="fabrikinput" name="' . $name . '" type="file" id="' . $id . '"' . $capture . ' />' . "\n";

		if ($params->get('fileupload_storage_type', 'filesystemstorage') == 'filesystemstorage' && $params->get('upload_allow_folderselect') == '1')
		{
			$rDir = JPATH_SITE . '/' . $params->get('ul_directory');
			$folders = JFolder::folders($rDir);
			$str[] = FabrikHelperHTML::folderAjaxSelect($folders);

			if ($groupModel->canRepeat())
			{
				$ulname = FabrikString::rtrimword($name, "[$repeatCounter]") . "[ul_end_dir][$repeatCounter]";
			}
			else
			{
				$ulname = $name . '[ul_end_dir]';
			}

			$str[] = '<input name="' . $ulname . '" type="hidden" class="folderpath"/>';
		}

		if ($params->get('ajax_upload'))
		{
			$str = array();
			$str[] = $allRenders;
			$str = $this->plupload($str, $repeatCounter, $values);
		}

		array_unshift($str, '<div class="fabrikSubElementContainer">');
		$str[] = '</div>';

		return implode("\n", $str);
	}

	/**
	 * Build the HTML to create the delete image button
	 *
	 * @param   string  $value  File to delete
	 *
	 * @return string
	 */

	protected function deleteButton($value)
	{
		return '<button class="btn button" data-file="' . $value . '">' . FText::_('COM_FABRIK_DELETE') . '</button> ';
	}

	/**
	 * Check if a single crop image has been uploaded and set the value accordingly
	 *
	 * @param   array  $value  Uploaded files
	 *
	 * @return mixed
	 */

	protected function checkForSingleCropValue($value)
	{
		$params = $this->getParams();

		// If its a single upload crop element
		if ($params->get('ajax_upload') && $params->get('ajax_max', 4) == 1)
		{
			$singleCropImg = $value;

			if (empty($singleCropImg))
			{
				$value = '';
			}
			else
			{
				$singleCropImg = $singleCropImg[0];
			}
		}

		return $value;
	}

	/**
	 * Make download link
	 *
	 * @param   string  $value          File path
	 * @param   array   $data           Row
	 * @param   int     $repeatCounter  Repeat counter
	 *
	 * @return	string	Download link
	 */

	protected function downloadLink($value, $data, $repeatCounter = 0)
	{
		$app = JFactory::getApplication();
		$package = $app->getUserState('com_fabrik.package', 'fabrik');
		$input = $app->input;
		$params = $this->getParams();
		$storage = $this->getStorage();
		$formModel = $this->getFormModel();

		if (empty($value) || !$storage->exists(COM_FABRIK_BASE . $value))
		{
			return '';
		}

		$canDownload = true;
		$aclEl = $this->getFormModel()->getElement($params->get('fu_download_acl', ''), true);

		if (!empty($aclEl))
		{
			$aclEl = $aclEl->getFullName();
			$canDownload = in_array($data[$aclEl], JFactory::getUser()->getAuthorisedViewLevels());
		}

		$formid = $formModel->getId();
		$rowid = $input->get('rowid', '0');
		$elementid = $this->getId();
		$title = basename($value);

		if ($params->get('fu_title_element') == '')
		{
			$title_name = $this->getFullName(true, false) . '__title';
		}
		else
		{
			$title_name = str_replace('.', '___', $params->get('fu_title_element'));
		}

		if (is_array($formModel->data))
		{
			if (array_key_exists($title_name, $formModel->data))
			{
				if (!empty($formModel->data[$title_name]))
				{
					$title = $formModel->data[$title_name];
					$titles = FabrikWorker::JSONtoData($title, true);
					$title = FArrayHelper::getValue($titles, $repeatCounter, $title);
				}
			}
		}

		$downloadImg = $params->get('fu_download_access_image');

		$layout = $this->getLayout('downloadlink');
		$data = new stdClass;
		$data->canDownload = $canDownload;
		$data->title = $title;
		$data->noAccessImage = COM_FABRIK_LIVESITE . 'media/com_fabrik/images/' . $params->get('fu_download_noaccess_image');
		$data->downloadImg = ($downloadImg && JFile::exists('media/com_fabrik/images/' . $downloadImg)) ? COM_FABRIK_LIVESITE . 'media/com_fabrik/images/' . $downloadImg : '';
		$data->href = COM_FABRIK_LIVESITE . 'index.php?option=com_' . $package
			. '&task=plugin.pluginAjax&plugin=fileupload&method=ajax_download&format=raw&element_id='
			. $elementid . '&formid=' . $formid . '&rowid=' . $rowid . '&repeatcount=' . $repeatCounter;


		return $layout->render($data);
	}

	/**
	 * Load the required plupload runtime engines
	 *
	 * @param   string  $runtimes  Runtimes
	 *
	 * @depreciated
	 *
	 * @return  void
	 */

	protected function pluploadLRuntimes($runtimes)
	{
		return;
	}

	/**
	 * Create the html for Ajax upload widget
	 *
	 * @param   array  $str            Current html output
	 * @param   int    $repeatCounter  Repeat group counter
	 * @param   array  $values         Existing files
	 *
	 * @return	array	Modified fileupload html
	 */

	protected function plupload($str, $repeatCounter, $values)
	{
		FabrikHelperHTML::stylesheet(COM_FABRIK_LIVESITE . 'media/com_fabrik/css/slider.css');
		$params = $this->getParams();
		$w = (int) $params->get('ajax_dropbox_width', 0);
		$h = (int) $params->get('ajax_dropbox_hight', 200);
		$dropBoxStyle = 'height:' . $h . 'px;';

		if ($w !== 0)
		{
			$dropBoxStyle .= 'width:' . $w . 'px;';
		}

		$basePath = COM_FABRIK_BASE . '/plugins/fabrik_element/fileupload/layouts/';
		$layout = new JLayoutFile('fileupload-widget', $basePath, array('debug' => false, 'component' => 'com_fabrik', 'client' => 'site'));

		$data = new stdClass;
		$data->id = $this->getHTMLId($repeatCounter);
		$data->winWidth = $params->get('win_width', 400);
		$data->winHeight = $params->get('win_height', 400);
		$data->canCrop = $this->canCrop();
		$data->canvasSupport = FabrikHelperHTML::canvasSupport();
		$data->dropBoxStyle = $dropBoxStyle;
		$data->field = implode("\n", $str);
		$data->j3 = FabrikWorker::j3();
		$pstr = (array) $layout->render($data);

		return $pstr;
	}

	/**
	 * Fabrik 3 - needs to be onAjax_upload not ajax_upload
	 * triggered by plupload widget
	 *
	 * @return  void
	 */

	public function onAjax_upload()
	{
		$app = JFactory::getApplication();
		$input = $app->input;
		$this->loadMeForAjax();

		/*
		 * Got this warning on fabrikar.com - not sure why set testing with errors off:
		 *
		 * <b>Warning</b>:  utf8_to_unicode: Illegal sequence identifier in UTF-8 at byte 0 in
		 * <b>/home/fabrikar/public_html/downloads/libraries/phputf8/utils/unicode.php</b> on line <b>110</b><br />
		 */
		/* error_reporting(0); */
		// $$$ hugh - reinstated this workaround, as I started getting those utf8 warnings as well.
		error_reporting(E_ERROR | E_PARSE);

		$o = new stdClass;
		$this->setId($input->getInt('element_id'));
		$this->loadMeForAjax();

		if (!$this->validate())
		{
			$o->error = $this->_validationErr;
			echo json_encode($o);

			return;
		}

		// Get parameters
		$chunk = $input->getInt('chunk', 0);
		$chunks = $input->getInt('chunks', 0);

		if ($chunk + 1 < $chunks)
		{
			return;
		}

		require_once COM_FABRIK_FRONTEND . '/helpers/uploader.php';

		// @TODO test in join
		if (array_key_exists('file', $_FILES) || array_key_exists('join', $_FILES))
		{
			$file = array(
				'name' => $_FILES['file']['name'],
				'type' => $_FILES['file']['type'],
				'tmp_name' => $_FILES['file']['tmp_name'],
				'error' => $_FILES['file']['error'],
				'size' => $_FILES['file']['size']
			);
			$filepath = $this->_processIndUpload($file, '', 0);
			$uri = $this->getStorage()->pathToURL($filepath);
			$o->filepath = $filepath;
			$o->uri = $uri;
		}
		else
		{
			$o->filepath = null;
			$o->uri = null;
		}

		echo json_encode($o);

		return;
	}

	/**
	 * Get database field description
	 *
	 * @return  string  db field type
	 */

	public function getFieldDescription()
	{
		if ($this->encryptMe())
		{
			return 'BLOB';
		}

		return "TEXT";
	}

	/**
	 * Attach documents to the email
	 *
	 * @param   string  $data  Data
	 *
	 * @return  string  Full path to image to attach to email
	 */

	public function addEmailAttachement($data)
	{
		if (is_object($data))
		{
			$data = $data->file;
		}

		// @TODO: check what happens here with open base_dir in effect
		$params = $this->getParams();

		if ($params->get('ul_email_file'))
		{
			if (empty($data))
			{
				$data = $params->get('default_image');
			}

			if (strstr($data, JPATH_SITE))
			{
				$p = str_replace(COM_FABRIK_LIVESITE, JPATH_SITE, $data);
			}
			else
			{
				$p = JPATH_SITE . '/' . $data;
			}

			return $p;
		}

		return false;
	}

	/**
	 * If a database join element's value field points to the same db field as this element
	 * then this element can, within modifyJoinQuery, update the query.
	 * E.g. if the database join element points to a file upload element then you can replace
	 * the file path that is the standard $val with the html to create the image
	 *
	 * @param   string  $val   Value
	 * @param   string  $view  Form or list
	 *
	 * @deprecated - doesn't seem to be used
	 *
	 * @return  string	Modified val
	 */

	protected function modifyJoinQuery($val, $view = 'form')
	{
		$params = $this->getParams();

		if (!$params->get('fu_show_image', 0) && $view == 'form')
		{
			return $val;
		}

		if ($params->get('make_thumbnail'))
		{
			$ulDir = JPath::clean($params->get('ul_directory')) . '/';
			$ulDir = str_replace("\\", "\\\\", $ulDir);
			$thumbDir = JPath::clean($params->get('thumb_dir')) . '/';
			$w = new FabrikWorker;
			$thumbDir = $w->parseMessageForPlaceHolder($thumbDir);
			$thumbDir = str_replace("\\", "\\\\", $thumbDir);

			$w = new FabrikWorker;
			$thumbDir = $w->parseMessageForPlaceHolder($thumbDir);
			$thumbDir .= $params->get('thumb_prefix');

			// Replace the backslashes with forward slashes
			$str = "CONCAT('<img src=\"" . COM_FABRIK_LIVESITE . "'," . "REPLACE(" . "REPLACE($val, '$ulDir', '" . $thumbDir . "')" . ", '\\\', '/')"
				. ", '\" alt=\"database join image\" />')";
		}
		else
		{
			$str = " REPLACE(CONCAT('<img src=\"" . COM_FABRIK_LIVESITE . "' , $val, '\" alt=\"database join image\"/>'), '\\\', '/') ";
		}

		return $str;
	}

	/**
	 * Trigger called when a row is deleted
	 *
	 * @param   array  $groups  Grouped data of rows to delete
	 *
	 * @return  void
	 */

	public function onDeleteRows($groups)
	{
		// Cant delete files from unpublished elements
		if (!$this->canUse())
		{
			return;
		}

		$db = $this->getListModel()->getDb();
		$user = JFactory::getUser();
		$storage = $this->getStorage();
		require_once COM_FABRIK_FRONTEND . '/helpers/uploader.php';
		$params = $this->getParams();

		if ($params->get('upload_delete_image', false))
		{
			jimport('joomla.filesystem.file');
			$elName = $this->getFullName(true, false);
			$name = $this->getElement()->name;

			foreach ($groups as $rows)
			{
				foreach ($rows as $row)
				{
					if (array_key_exists($elName . '_raw', $row))
					{
						if ($this->isJoin())
						{
							$join = $this->getJoinModel()->getJoin();
							$query = $db->getQuery(true);
							$query->select('*')->from($db->quoteName($join->table_join))
								->where($db->quoteName('parent_id') . ' = ' . $db->quote($row->__pk_val));
							$db->setQuery($query);
							$imageRows = $db->loadObjectList('id');

							if (!empty($imageRows))
							{
								foreach ($imageRows as $imageRow)
								{
									$this->deleteFile($imageRow->$name);
								}

								$query->clear();
								$query->delete($db->quoteName($join->table_join))
									->where($db->quoteName('id') . ' IN (' . implode(', ', array_keys($imageRows)) . ')');
								$db->setQuery($query);
								$logMsg = 'onDeleteRows Delete records query: ' . $db->getQuery() . '; user = ' . $user->get('id');
								JLog::add($logMsg, JLog::WARNING, 'com_fabrik.element.fileupload');
								$db->execute();
							}
						}
						else
						{
							$files = explode(GROUPSPLITTER, $row->{$elName . '_raw'});

							foreach ($files as $filename)
							{
								$this->deleteFile(trim($filename));
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Return the number of bytes
	 *
	 * @param   string  $val  E.g. 3m
	 *
	 * @return  int  Bytes
	 */

	protected function _return_bytes($val)
	{
		$val = trim($val);
		$last = String::strtolower(substr($val, -1));

		if ($last == 'g')
		{
			$val = $val * 1024 * 1024 * 1024;
		}

		if ($last == 'm')
		{
			$val = $val * 1024 * 1024;
		}

		if ($last == 'k')
		{
			$val = $val * 1024;
		}

		return $val;
	}

	/**
	 * Get the max upload size allowed by the server.
	 *
	 * @deprecated  - not used?
	 *
	 * @return  int  kilobyte upload size
	 */

	public function maxUpload()
	{
		$post_value = $this->_return_bytes(ini_get('post_max_size'));
		$upload_value = $this->_return_bytes(ini_get('upload_max_filesize'));
		$value = min($post_value, $upload_value);
		$value = $value / 1024;

		return $value;
	}

	/**
	 * Turn form value into email formatted value
	 *
	 * @param   mixed  $value          element value
	 * @param   array  $data           form data
	 * @param   int    $repeatCounter  group repeat counter
	 *
	 * @return  string  email formatted value
	 */

	public function getEmailValue($value, $data = array(), $repeatCounter = 0)
	{
		$params = $this->getParams();
		$storage = $this->getStorage();
		$this->_repeatGroupCounter = $repeatCounter;

		if ($params->get('fu_show_image_in_email', false))
		{
			$origShowImages = $params->get('fu_show_image');
			$params->set('fu_show_image', true);

			// For ajax repeats
			$value = (array) $value;
			$formModel = $this->getFormModel();

			if (!isset($formModel->data))
			{
				$formModel->data = $data;
			}

			if (empty($value))
			{
				return '';
			}

			foreach ($value as $v)
			{
				$render = $this->loadElement($v);

				if ($v != '' && $storage->exists(COM_FABRIK_BASE . $v))
				{
					$render->render($this, $params, $v);
				}
			}

			if ($render->output == '' && $params->get('default_image') != '')
			{
				$render->output = '<img src="' . $params->get('default_image') . '" alt="image" />';
			}

			return $render->output;
		}
		else
		{
			return $storage->preRenderPath($value);
		}
	}

	/**
	 * Determines the value for the element in the form view
	 *
	 * @param   array  $data           Form data
	 * @param   int    $repeatCounter  When repeating joined groups we need to know what part of the array to access
	 *
	 * @return  string	Value
	 */

	public function getROValue($data, $repeatCounter = 0)
	{
		$v = $this->getValue($data, $repeatCounter);
		$storage = $this->getStorage();

		return $storage->pathToURL($v);
	}

	/**
	 * Not really an AJAX call, we just use the pluginAjax method so we can run this
	 * method for handling scripted downloads.
	 *
	 * @return  void
	 */

	public function onAjax_download()
	{
		$app = JFactory::getApplication();
		$input = $app->input;
		$this->setId($input->getInt('element_id'));
		$this->loadMeForAjax();
		$this->getElement();
		$params = $this->getParams();
		$url = $input->server->get('HTTP_REFERER', '', 'string');
		$lang = JFactory::getLanguage();
		$lang->load('com_fabrik.plg.element.fabrikfileupload', JPATH_ADMINISTRATOR);
		$rowid = $input->get('rowid', '', 'string');
		$repeatcount = $input->getInt('repeatcount', 0);
		$listModel = $this->getListModel();
		$row = $listModel->getRow($rowid, false, true);

		if (!$this->canView())
		{
			$app->enqueueMessage(FText::_('PLG_ELEMENT_FILEUPLOAD_DOWNLOAD_NO_PERMISSION'));
			$app->redirect($url);
			exit;
		}

		if (empty($rowid))
		{
			$app->enqueueMessage(FText::_('PLG_ELEMENT_FILEUPLOAD_DOWNLOAD_NO_SUCH_FILE'));
			$app->redirect($url);
			exit;
		}

		if (empty($row))
		{
			$app->enqueueMessage(FText::_('PLG_ELEMENT_FILEUPLOAD_DOWNLOAD_NO_SUCH_FILE'));
			$app->redirect($url);
			exit;
		}

		$aclEl = $this->getFormModel()->getElement($params->get('fu_download_acl', ''), true);

		if (!empty($aclEl))
		{
			$aclEl = $aclEl->getFullName();
			$aclElraw = $aclEl . '_raw';
			$user = JFactory::getUser();
			$groups = $user->getAuthorisedViewLevels();
			$canDownload = in_array($row->$aclElraw, $groups);

			if (!$canDownload)
			{
				$app->enqueueMessage(FText::_('PLG_ELEMENT_FILEUPLOAD_DOWNLOAD_NO_PERMISSION'));
				$app->redirect($url);
			}
		}

		$storage = $this->getStorage();
		$elName = $this->getFullName(true, false);
		$filepath = $row->$elName;
		$filepath = FabrikWorker::JSONtoData($filepath, false);
		$filepath = is_object($filepath) ? FArrayHelper::fromObject($filepath) : $filepath;
		$foo = $filepath[$repeatcount];
		$filepath = FArrayHelper::getValue($filepath, $repeatcount);
		$filepath = $storage->getFullPath($filepath);
		$filecontent = $storage->read($filepath);

		if ($filecontent !== false)
		{
			$thisFileInfo = $storage->getFileInfo($filepath);

			if ($thisFileInfo === false)
			{
				$app->enqueueMessage(FText::_('DOWNLOAD NO SUCH FILE'));
				$app->redirect($url);
				exit;
			}
			// Some time in the past
			header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
			header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
			header("Cache-Control: no-store, no-cache, must-revalidate");
			header("Cache-Control: post-check=0, pre-check=0", false);
			header("Pragma: no-cache");
			header('Accept-Ranges: bytes');
			header('Content-Length: ' . $thisFileInfo['filesize']);
			header('Content-Type: ' . $thisFileInfo['mime_type']);
			header('Content-Disposition: attachment; filename="' . $thisFileInfo['filename'] . '"');

			// Serve up the file
			echo $filecontent;

			// $this->downloadEmail($row, $filepath);
			$this->downloadHit($rowid, $repeatcount);
			$this->downloadLog($row, $filepath);

			// And we're done.
			exit();
		}
		else
		{
			$app->enqueueMessage(FText::_('PLG_ELEMENT_FILEUPLOAD_DOWNLOAD_NO_SUCH_FILE'));
			$app->redirect($url);
			exit;
		}
	}

	/**
	 * Update downloads hits table
	 *
	 * @param   int|string  $rowid        Update table's primary key
	 * @param   int         $repeatCount  Repeat group counter
	 *
	 * @return  void
	 */

	protected function downloadHit($rowid, $repeatCount = 0)
	{
		// $$$ hugh @TODO - make this work for repeats and/or joins!
		$params = $this->getParams();

		if ($hit_counter = $params->get('fu_download_hit_counter', ''))
		{
			JError::setErrorHandling(E_ALL, 'ignore');
			$listModel = $this->getListModel();
			$pk = $listModel->getTable()->db_primary_key;
			$fabrikDb = $listModel->getDb();
			list($table_name, $element_name) = explode('.', $hit_counter);
			$sql = "UPDATE $table_name SET $element_name = COALESCE($element_name,0) + 1 WHERE $pk = " . $fabrikDb->quote($rowid);
			$fabrikDb->setQuery($sql);
			$fabrikDb->execute();
		}
	}

	/**
	 * Log the download
	 *
	 * @param   object  $row       Log download row
	 * @param   string  $filepath  Downloaded file's path
	 *
	 * @since 2.0.5
	 *
	 * @return  void
	 */

	protected function downloadLog($row, $filepath)
	{
		$params = $this->getParams();

		if ((int) $params->get('fu_download_log', 0))
		{
			$app = JFactory::getApplication();
			$input = $app->input;
			JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fabrik/tables');
			$log = JTable::getInstance('log', 'Table');
			$log->message_type = 'fabrik.fileupload.download';
			$user = JFactory::getUser();
			$msg = new stdClass;
			$msg->file = $filepath;
			$msg->userid = $user->get('id');
			$msg->username = $user->get('username');
			$msg->email = $user->get('email');
			$log->referring_url = $input->server->get('REMOTE_ADDR', '', 'string');
			$log->message = json_encode($msg);
			$log->store();
		}
	}

	/**
	 * Called when save as copy form button clicked
	 *
	 * @param   mixed  $val  Value to copy into new record
	 *
	 * @return  mixed  Value to copy into new record
	 */

	public function onSaveAsCopy($val)
	{
		if (empty($val))
		{
			$formModel = $this->getFormModel();
			$groupModel = $this->getGroupModel();
			$isjoin = $groupModel->isJoin();
			$origData = $formModel->getOrigData();
			$groupModel = $this->getGroup();

			if ($isjoin)
			{
				$name = $this->getFullName(true, false);
				$joinid = $groupModel->getGroup()->join_id;
			}
			else
			{
				$name = $this->getFullName(true, false);
			}

			$val = $origData[$name];
		}

		return $val;
	}

	/**
	 * Is the element a repeating element
	 *
	 * @return  bool
	 */

	public function isRepeatElement()
	{
		$params = $this->getParams();

		return $params->get('ajax_upload') && ($params->get('ajax_max', 4) > 1);
	}

	/**
	 * Fabrik 3: needs to be onAjax_deleteFile
	 * delete a previously uploaded file via ajax
	 *
	 * @return  void
	 */

	public function onAjax_deleteFile()
	{
		$app = JFactory::getApplication();
		$user = JFactory::getUser();
		$input = $app->input;
		$this->loadMeForAjax();
		$filename = $input->get('file', 'string', '');

		// Filename may be a path - if so just get the name
		if (strstr($filename, '/'))
		{
			$filename = explode('/', $filename);
			$filename = array_pop($filename);
		}
		elseif (strstr($filename, '\\'))
		{
			$filename = explode('\\', $filename);
			$filename = array_pop($filename);
		}

		$repeatCounter = (int) $input->getInt('repeatCounter');
		$join = FabTable::getInstance('join', 'FabrikTable');
		$join->load(array('element_id' => $input->getInt('element_id')));
		$this->setId($input->getInt('element_id'));
		$this->getElement();

		$filepath = $this->_getFilePath($repeatCounter);
		$filepath = str_replace(JPATH_SITE, '', $filepath);

		$storage = $this->getStorage();
		$filename = $storage->cleanName($filename, $repeatCounter);
		$filename = JPath::clean($filepath . '/' . $filename);
		$this->deleteFile($filename);
		$db = $this->getListModel()->getDb();
		$query = $db->getQuery(true);

		// Could be a single ajax fileupload if so not joined
		if ($join->table_join != '')
		{
			// Use getString as if we have edited a record, added a file and deleted it the id is alphanumeric and not found in db.
			$query->delete($db->quoteName($join->table_join))
			->where($db->quoteName('id') . ' = ' . $db->quote($input->getString('recordid')));
			$db->setQuery($query);

			JLog::add('Delete join image entry: ' . $db->getQuery() . '; user = ' . $user->get('id'), JLog::WARNING, 'com_fabrik.element.fileupload');
			$db->execute();
		}
	}

	/**
	 * Determines the value for the element in the form view
	 *
	 * @param   array  $data           Element value
	 * @param   int    $repeatCounter  When repeating joined groups we need to know what part of the array to access
	 * @param   array  $opts           Options
	 *
	 * @return	string	Value
	 */

	public function getValue($data, $repeatCounter = 0, $opts = array())
	{
		$value = parent::getValue($data, $repeatCounter, $opts);

		return $value;
	}

	/**
	 * Build 'slideshow' / carousel.  What gets built will depend on content type,
	 * using the first file in the data array as the type.  So if the first file is
	 * an image, a Bootstrap carousel will be built.
	 *
	 * @param   string  $id       Widget HTML id
	 * @param   array   $data     Array of file paths
	 * @param   object  $thisRow  Row data
	 *
	 * @return  string  HTML
	 */

	public function buildCarousel($id = 'carousel', $data = array(), $thisRow = null)
	{
		$rendered = '';

		if (!FArrayHelper::emptyIsh($data))
		{
			$render = $this->loadElement($data[0]);
			$params = $this->getParams();
			$rendered = $render->renderCarousel($id, $data, $this, $params, $thisRow);
		}

		return $rendered;
	}

	/**
	 * run on formModel::setFormData()
	 * TESTING - stick the filename (if it's there) in to the formData, so things like validations
	 * can see it.  Not sure yet if this will mess with the rest of the code.  And I'm sure it'll get
	 * horribly funky, judging by the code in processUpload!  But hey, let's have a hack at it
	 *
	 * @param   int  $c  Repeat group counter
	 *
	 * @return void
	 */

	public function preProcess_off($c)
	{
		$params = $this->getParams();
		$w = new FabrikWorker;
		$form = $this->getFormModel();
		$data = unserialize(serialize($form->formData));
		$group = $this->getGroup();

		/**
		 * get the key name in dot format for updateFormData method
		 * $$$ hugh - added $rawkey stuff, otherwise when we did "$key . '_raw'" in the updateFormData
		 * below on repeat data, it ended up in the wrong format, like join.XX.table___element.0_raw
		*/
		$key = $this->getFullName(true, false);
		$shortkey = $this->getFullName(true, false);
		$rawkey = $key . '_raw';

		if (!$group->canRepeat())
		{
			if (!$this->isRepeatElement())
			{
				$farray = FArrayHelper::getValue($_FILES, $key, array(), 'array');
				$fname = FArrayHelper::getValue($farray, 'name');
				$form->updateFormData($key, $fname);
				$form->updateFormData($rawkey, $fname);
			}
		}
	}
}
