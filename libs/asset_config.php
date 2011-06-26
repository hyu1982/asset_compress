<?php
/**
 * Parses the ini files AssetCompress uses into arrays that
 * other objects can use.
 *
 * @package asset_compress
 */
class AssetConfig {

	protected $_data = array();

/**
 * A hash of constants that can be expanded when reading ini files.
 *
 * @var array
 */
	public $constantMap = array(
		'APP/' => APP, 
		'WEBROOT/' => WWW_ROOT
	);

	const FILTERS = 'filters';
	const FILTER_PREFIX = 'filter_';
	const TARGETS = 'targets';

/**
 * Constructor, set some initial data for a AssetConfig object. 
 *
 * @param array $data Initial data set for the object.
 * @param array $additionalConstants  Additional constants that will be translated 
 *    when parsing paths.
 */
	public function __construct(array $data = array(), array $additionalConstants = array()) {
		$this->_data = $data;
		$this->constantMap = array_merge($this->constantMap, $additionalConstants);
	}

/**
 * Constructor
 *
 * @param string $iniFile File path for the ini file to parse.
 * @param array $additionalConstants  Additional constants that will be translated 
 *    when parsing paths.
 */
	public static function buildFromIniFile($iniFile = null, $constants = array()){
		if (empty($iniFile)) {
			$iniFile = CONFIGS . 'asset_compress.ini';
		}
		$contents = self::_readConfig($iniFile);
		return self::_parseConfig($contents, $constants);
	}

/**
 *
 * @param string $filename Name of the inifile to parse
 */
	protected static function _readConfig($filename) {
		if (empty($filename) || !is_string($filename) || !file_exists($filename)) {
			throw new RuntimeException(sprintf('Configuration file "%s" was not found.', $filename));
		}
		return parse_ini_file($filename, true);
	}

/**
 * Transforms the config data into a more structured form
 *
 * @param array $contents Contents to build a config object from.
 * @return AssetConfig
 */
	protected static function _parseConfig($config, $constants) {
		$AssetConfig = new AssetConfig(array(), $constants);
		foreach ($config as $section => $values) {
			if (strpos($section, '_') === false) {
				// extension section
				$AssetConfig->addExtension($section, $values);
			} elseif (strpos($section, self::FILTER_PREFIX) === 0) {
				// filter section.
				$name = str_replace(self::FILTER_PREFIX, '', $section);
				$AssetConfig->filterConfig($name, $values);
			} else {
				list($extension, $key) = explode('_', $section, 2);
				// must be a build target.
				$files = isset($values['files']) ? $values['files'] : array();
				$filters = isset($values['filters']) ? $values['filters'] : array();
				$AssetConfig->addTarget($key, $files, $filters);
			}
		}
		return $AssetConfig;
	}

/**
 * Add/Replace an extension configuration.
 *
 * @param string $ext Extension name
 * @param array $config Configuration for the extension
 * @return void
 */
	public function addExtension($ext, array $config) {
		$this->_data[$ext] = $this->_parseExtensionDef($config);
		if (!empty($this->_data[$ext][self::FILTERS])) {
			foreach ($this->_data[$ext][self::FILTERS] as $filter) {
				if (empty($this->_data[self::FILTERS][$filter])) {
					$this->_data[self::FILTERS][$filter] = array();
				}
			}
		}
	}

/**
 * Parses paths in an extension definintion
 *
 * @param array $data Array of extension information.
 * @return array Array of build extension information with paths replaced.
 */
	protected function _parseExtensionDef($target) {
		$paths = array();
		if (!empty($target['paths'])) {
			$paths = array_map(array($this, '_replacePathConstants'), (array) $target['paths']);
		}
		$target['paths'] = $paths;
		if (!empty($target['cachePath'])) {
			$target['cachePath'] = $this->_replacePathConstants($target['cachePath']);
		}
		return $target;
	}

/**
 * Replaces the file path constants used in Config files.
 * Will replace APP and WEBROOT
 *
 * @param string $path Path to replace constants on
 * @return string constants replaced
 */
	protected function _replacePathConstants($path) {
		return str_replace(array_keys($this->constantMap), array_values($this->constantMap), $path);
	}

/**
 * Set values into the config object, You can't modify targets, or filters
 * with this.  Use the appropriate methods for those settings.
 *
 * @param string $path The path to set.
 * @param string $value The value to set.
 */
	public function set($path, $value) {
		$parts = explode('.', $path);
		if (count($parts) > 2) {
			throw new RuntimeException('Only depth of two can be written to.');
		}
		$stack =& $this->_data;
		while (!empty($parts)) {
			$key = array_shift($parts);
			if (empty($stack[$key]) && !empty($parts)) {
				$stack[$key] = array();
			}
			if (!empty($parts)) {
				$stack =& $stack[$key];
			} else {
				$stack[$key] = $value;
			}
		}
	}

/**
 * Get values from the config data.
 *
 * @param string $path The path you want.
 */
	public function get($path) {
		$parts = explode('.', $path);
		$stack =& $this->_data;
		while (!empty($parts)) {
			$key = array_shift($parts);
			$moreKeys = !empty($parts);
			if (isset($stack[$key]) && $moreKeys) {
				$stack =& $stack[$key];
			} elseif (!$moreKeys) {
				return isset($stack[$key]) ? $stack[$key] : null;
			}
		}
	}

/**
 * Get/set filters for an extension/build file
 *
 * @param string $ext Name of an extension
 * @param string $target A build target. If provided the target's filters (if any) will also be 
 *     returned.
 * @param array $filters Filters to replace either the global or per target filters.
 * @return array Filters for that extension.
 */
	public function filters($ext, $target = null, $filters = null) {
		if ($filters === null) {
			if (!isset($this->_data[$ext][self::FILTERS])) {
				return array();
			}
			$filters = (array)$this->_data[$ext][self::FILTERS];
			if ($target !== null && !empty($this->_data[$ext][self::TARGETS][$target][self::FILTERS])) {
				$buildFilters = $this->_data[$ext][self::TARGETS][$target][self::FILTERS];
				$filters = array_merge($filters, $buildFilters);
			}
			return array_unique($filters);
		}
		if ($target === null) {
			$this->_data[$ext][self::FILTERS] = $filters;
			foreach ($filters as $f) {
				if (empty($this->_data[self::FILTERS][$f])) {
					$this->_data[self::FILTERS][$f] = array();
				}
			}
		} else {
			$this->_data[$ext][self::TARGETS][$target][self::FILTERS] = $filters;
		}
	}

/**
 * Get/Set filter Settings.
 *
 * @param string $filter The filter name
 * @param array $settings The settings to set, leave null to get
 * @return mixed.
 */
	public function filterConfig($filter, $settings = null) {
		if ($settings === null) {
			if (is_string($filter)) {
				return isset($this->_data[self::FILTERS][$filter]) ? $this->_data[self::FILTERS][$filter] : array();
			}
			if (is_array($filter)) {
				$result = array();
				foreach ($filter as $f) {
					$result[$f] = $this->filterConfig($f);
				}
				return $result;
			}
		}
		$this->_data[self::FILTERS][$filter] = $settings;
	}

/**
 * Get/set the list of files that match the given build file.
 *
 * @param string $target The build file with extension.
 * @return array An array of files for the chosen build.
 */
	public function files($target, $files = null) {
		$ext = $this->getExt($target);
		if ($files === null) {
			if (isset($this->_data[$ext][self::TARGETS][$target]['files'])) {
				return (array)$this->_data[$ext][self::TARGETS][$target]['files'];
			}
			return array();
		}
		$this->_data[$ext][self::TARGETS][$target]['files'] = $files;
	}

/**
 * Get the extension for a filename.
 *
 * @param string $file
 * @return string
 */
	public function getExt($file) {
		return substr($file, strrpos($file, '.') + 1);
	}

/**
 * Get/set paths for an extension. Setting paths will replace
 * all existing paths. Its only intended for testing.
 *
 * @param string $ext Extension to get paths for.
 * @return array An array of paths to search for assets on.
 */
	public function paths($ext, $paths = null) {
		if ($paths === null) {
			if (!empty($this->_data[$ext]['paths'])) {
				return (array) $this->_data[$ext]['paths'];
			}
			return array();
		}
		$this->_data[$ext]['paths'] = array_map(array($this, '_replacePathConstants'), $paths);
	}

/**
 * Accessor for getting the cachePath for a given extension.
 *
 * @param string $ext Extension to get paths for.
 * @param string $path The path to cache files using $ext to.
 */
	public function cachePath($ext, $path = null) {
		if ($path === null) {
			if (isset($this->_data[$ext]['cachePath'])) {
				return $this->_data[$ext]['cachePath'];
			}
			return '';
		}
		$this->_data[$ext]['cachePath'] = $this->_replacePathConstants($path);
	}

/**
 * Check to see if caching is on for an extension.
 * Caching is controlled by General.writeCache and the matching 
 * extension having a cachePath.
 *
 * @param string $target
 * @return boolean
 */
	public function cachingOn($target) {
		$ext = $this->getExt($target);
		if ($this->get('General.writeCache') && $this->cachePath($ext)) {
			return true;
		}
		return false;
	}

/**
 * Get the build targets for an extension.
 *
 * @param string $ext The extension you want targets for.
 * @return array An array of build targets for the extension.
 */
	public function targets($ext) {
		if (empty($this->_data[$ext][self::TARGETS])) {
			return array();
		}
		return array_keys($this->_data[$ext][self::TARGETS]);
	}

/**
 * Create a new build target.
 *
 * @param string $target Name of the target file.  The extension will be inferred based on the last extension.
 * @param array $files Files to combine the build file from.
 */
	public function addTarget($target, array $files, $filters = array()) {
		$ext = $this->getExt($target);
		$this->_data[$ext][self::TARGETS][$target] = array(
			'files' => $files,
			'filters' => $filters
		);
	}

/**
 * Get the list of extensions this config object supports.
 *
 * @return array Extension list.
 */
	public function extensions() {
		$exts = array_flip(array_keys($this->_data));
		unset($exts[self::FILTERS], $exts['General']);
		return array_keys($exts);
	}
}
