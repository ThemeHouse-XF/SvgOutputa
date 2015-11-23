<?php

/**
 * Class to output SVG data quickly for public facing pages. This class
 * is not designed to be used with the MVC structure; this allows us to
 * significantly reduce the amount of overhead in a request.
 *
 * This class is entirely self sufficient. It handles parsing the input,
 * getting the data, rendering it, and manipulating HTTP headers.
 */
class ThemeHouse_SvgOutput
{
	/**
	 * Style ID the SVG will be retrieved from.
	 *
	 * @var integer
	 */
	protected $_styleId = 0;

	/**
	 * Language ID the SVG will be retrieved from.
	 *
	 * @var integer
	 */
	protected $_languageId = 0;

	/**
	 * SVG template that has been requested. This will have ".svg" appended
	 * to it and requested as a template.
	 *
	 * @var array
	 */
	protected $_svgRequested = '';

	/**
	 * The timestamp of the last modification, according to the input. (Used to compare
	 * to If-Modified-Since header.)
	 *
	 * @var integer
	 */
	protected $_inputModifiedDate = 0;

	/**
	 * The direction in which text should be rendered. Either ltr or rtl.
	 *
	 * @var string
	 */
	protected $_textDirection = 'LTR';

	/**
	 * Date of the last modification to the style. Used to output Last-Modified header.
	 *
	 * @var integer
	 */
	protected $_styleModifiedDate = 0;

	/**
	 * Constructor.
	 *
	 * @param array $input Array of input. Style, language and SVG will be
	 * pulled from this.
	 */
	public function __construct(array $input)
	{
		$this->parseInput($input);
	} /* END __construct */

	/**
	 * Parses the style ID, language ID and the SVG out of the specified array
	 * of input. The style ID will be found in "style", language ID will be
	 * found in "language" and SVG in "svg".
	 *
	 * @param array $input
	 */
	public function parseInput(array $input)
	{
		$this->_styleId = isset($input['style']) ? intval($input['style']) : 0;

		$this->_languageId = isset($input['language']) ? intval($input['language']) : 0;

		$this->_svgRequested = strval($input['svg']);

		if (!empty($input['d'])) {
			$this->_inputModifiedDate = intval($input['d']);
		}
	} /* END parseInput */

	public function handleIfModifiedSinceHeader(array $server)
	{
		$outputSvg = true;
		if (isset($server['HTTP_IF_MODIFIED_SINCE'])) {
			$modDate = strtotime($server['HTTP_IF_MODIFIED_SINCE']);
			if ($modDate !== false && $this->_inputModifiedDate <= $modDate) {
				header('HTTP/1.1 304 Not Modified', true, 304);
				$outputSvg = false;
			}
		}

		return $outputSvg;
	} /* END handleIfModifiedSinceHeader */

	/**
	 * Does any preperations necessary for outputting to be done.
	 */
	protected function _prepareForOutput()
	{
		$styles = XenForo_Application::get('styles');

		if ($this->_styleId && isset($styles[$this->_styleId])) {
			$style = $styles[$this->_styleId];
		} else {
			$style = reset($styles);
		}

		if ($style) {
			$properties = unserialize($style['properties']);

			$this->_styleId = $style['style_id'];
			$this->_styleModifiedDate = $style['last_modified_date'];
		} else {
			$properties = array();

			$this->_styleId = 0;
		}

		$languages = XenForo_Application::get('languages');

		if ($this->_languageId && isset($languages[$this->_languageId])) {
		    $language = $languages[$this->_languageId];
		} else {
		    $language = reset($languages);
		}

		if ($language) {
		    $this->_textDirection = $language['text_direction'];
		    $this->_languageId = $language['language_id'];
		} else {
		    $this->_textDirection = 'LTR';
		    $this->_languageId = 0;
		}

		XenForo_Template_Helper_Core::setStyleProperties($properties, false);
		XenForo_Template_Public::setStyleId($this->_styleId);
		XenForo_Template_Abstract::setLanguageId($this->_languageId);
	} /* END _prepareForOutput */

	/**
	 * Renders the SVG and returns it.
	 *
	 * @return string
	 */
	public function renderSvg()
	{
		$cacheId = 'xfSvgCache_' . sha1(
			'style=' . $this->_styleId .
			'language=' . $this->_languageId .
		    'svg=' . $this->_svgRequested .
			'd=' . $this->_inputModifiedDate .
			'dir=' . $this->_textDirection);

		if ($cacheObject = XenForo_Application::getCache()) {
			if ($cacheObject->test($cacheId)) {
				return $cacheObject->load($cacheId, true);
			}
		}

		$this->_prepareForOutput();

		$params = array(
			'xenOptions' => XenForo_Application::get('options')->getOptions(),
			'dir' => $this->_textDirection,
			'pageIsRtl' => ($this->_textDirection == 'RTL')
		);

		$svgName = trim($this->_svgRequested);
		if (!$svgName) {
			return;
		}

		$templateName = $svgName . '.svg';
		$template = new XenForo_Template_Public($templateName, $params);

		$svg = self::renderSvgFromObject($template);

		if ($cacheObject) {
			$cacheObject->save($svg, $cacheId);
		}

		return $svg;
	} /* END renderSvg */

	/**
	 * Renders the SVG from a Template object.
	 *
	 * @param XenForo_Template_Abstract $template
	 *
	 * @return string
	 */
	public static function renderSvgFromObject(XenForo_Template_Abstract $template)
	{
		return $template->render();
	} /* END renderSvgFromObject */

	/**
	 * Outputs the specified SVG. Also outputs the necessary HTTP headers.
	 *
	 * @param string $svg
	 */
	public function displaySvg($svg)
	{
		header('Content-type: image/svg+xml; charset=utf-8');
		header('Expires: Wed, 01 Jan 2020 00:00:00 GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $this->_styleModifiedDate) . ' GMT');
		header('Cache-Control: public');

		$extraHeaders = XenForo_Application::gzipContentIfSupported($svg);
		foreach ($extraHeaders AS $extraHeader) {
			header("$extraHeader[0]: $extraHeader[1]", $extraHeader[2]);
		}

		if (is_string($svg) && $svg && !ob_get_level() && XenForo_Application::get('config')->enableContentLength) {
			header('Content-Length: ' . strlen($svg));
		}

		echo $svg;
	} /* END displaySvg */

	/**
	 * Static helper to execute a full request for SVG output. This will
	 * instantiate the object, pull the data from $_REQUEST, and then output
	 * the SVG.
	 */
	public static function run()
	{
		$dependencies = new XenForo_Dependencies_Public();
		$dependencies->preLoadData();

		$svgOutput = new self($_REQUEST);
		if ($svgOutput->handleIfModifiedSinceHeader($_SERVER)) {
			$svgOutput->displaySvg($svgOutput->renderSvg());
		}
	} /* END run */
}