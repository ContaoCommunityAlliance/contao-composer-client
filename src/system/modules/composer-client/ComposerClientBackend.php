<?php

use Composer\Composer;
use Composer\Factory;
use Composer\Installer;
use Composer\Console\HtmlOutputFormatter;
use Composer\IO\BufferIO;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\ComposerRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Util\ConfigValidator;

class ComposerClientBackend extends BackendModule
{
	protected $strTemplate = 'be_composer_client';

	/**
	 * Compile the current element
	 */
	protected function compile()
	{
		$this->loadLanguageFile('composer_client');

		$input = Input::getInstance();

		if ($GLOBALS['TL_CONFIG']['useFTP']) {
			// switch template
			$this->Template->setName('be_composer_client_ftp_mode');

			return;
		}

		if (!file_exists(TL_ROOT . '/composer/composer.phar')) {
			// switch template
			$this->Template->setName('be_composer_client_install_composer');

			// do install composer library
			if ($input->post('install')) {
				$this->updateComposer();
				$this->reload();
			}

			return;
		}

		if ($input->get('update') == 'composer') {
			$this->updateComposer();
			$this->redirect('contao/main.php?do=composer');
		}

		if ($input->get('update') == 'database') {
			if (in_array('rep_client', $this->Config->getActiveModules())) {
				$this->redirect('contao/main.php?do=repository_manager');
			}
			else {
				$this->redirect('contao/install.php');
			}
		}

		chdir(TL_ROOT . '/composer');

		// unregister contao class loader
		spl_autoload_unregister('__autoload');

		// try to increase memory limit
		$this->increaseMemoryLimit();

		// register embeded class loader
		$phar             = new Phar(TL_ROOT . '/composer/composer.phar');
		$autoloadPathname = $phar['vendor/autoload.php'];
		require_once($autoloadPathname->getPathname());

		// reregister contao class loader
		spl_autoload_register('__autoload');

		// define pathname to config file
		$configPathname = 'composer/' . Factory::getComposerFile();

		// create io interace
		$io = new BufferIO('', null, new HtmlOutputFormatter());

		if ($input->get('settings') == 'experts') {
			$configFile = new File($configPathname);

			if ($input->post('save')) {
				$tempPathname = $configPathname . '~';
				$tempFile     = new File($tempPathname);

				$config = $input->post('config');
				$config = html_entity_decode($config, ENT_QUOTES, 'UTF-8');

				$tempFile->write($config);
				$tempFile->close();

				$validator = new ConfigValidator($io);
				list($errors, $publishErrors, $warnings) = $validator->validate(TL_ROOT . '/' . $tempPathname);

				if (!$errors && !$publishErrors) {
					$_SESSION['TL_CONFIRM'][] = $GLOBALS['TL_LANG']['composer_client']['configValid'];
					$this->import('Files');
					$this->Files->rename($tempPathname, $configPathname);
				}
				else {
					$tempFile->delete();
					$_SESSION['COMPOSER_EDIT_CONFIG'] = $config;

					if ($errors) {
						var_dump($_SESSION['TL_ERROR']);
						foreach ($errors as $message) {
							$_SESSION['TL_ERROR'][] = 'Error: ' . $message;
						}
					}

					if ($publishErrors) {
						foreach ($publishErrors as $message) {
							$_SESSION['TL_ERROR'][] = 'Publish error: ' . $message;
						}
					}
				}

				if ($warnings) {
					foreach ($warnings as $message) {
						$_SESSION['TL_ERROR'][] = 'Warning: ' . $message;
					}
				}

				$this->reload();
			}

			if (isset($_SESSION['COMPOSER_EDIT_CONFIG'])) {
				$config = $_SESSION['COMPOSER_EDIT_CONFIG'];
				unset($_SESSION['COMPOSER_EDIT_CONFIG']);
			}
			else {
				$config = $configFile->getContent();
			}
			$this->Template->setName('be_composer_client_editor');
			$this->Template->config = $config;
			return;
		}

		// search for composer build version
		$composerDevWarningTime = $this->readComposerDevWarningTime();
		if (!$composerDevWarningTime || time() > $composerDevWarningTime) {
			$_SESSION['TL_ERROR'][]         = $GLOBALS['TL_LANG']['composer_client']['composerUpdateRequired'];
			$this->Template->composerUpdate = true;
		}

		// create composer factory
		/** @var \Composer\Factory $factory */
		$factory = new Factory();

		// create composer
		/** @var \Composer\Composer $composer */
		$composer                 = $factory->createComposer($io);
		$this->Template->composer = $composer;

		// do search
		if ($input->get('keyword')) {
			$keyword = $input->get('keyword');

			$tokens = explode(' ', $keyword);
			$tokens = array_map('trim', $tokens);
			$tokens = array_filter($tokens);

			if (empty($tokens)) {
				$_SESSION['COMPOSER_OUTPUT'] = $io->getOutput();
				$this->redirect('contao/main.php?do=composer');
			}

			$packages = $this->searchPackages($composer, $tokens, $input->get('cache') == 'no');

			if (empty($packages)) {
				$_SESSION['TL_ERROR'][] = sprintf(
					$GLOBALS['TL_LANG']['composer_client']['noSearchResult'],
					$keyword
				);

				$_SESSION['COMPOSER_OUTPUT'] = $io->getOutput();
				$this->redirect('contao/main.php?do=composer');
			}

			$this->Template->setName('be_composer_client_search');
			$this->Template->keyword  = $keyword;
			$this->Template->packages = $packages;
			return;
		}

		// do install
		if ($input->get('install')) {
			$packageName = $input->get('install');

			if ($input->post('version')) {
				$version = $input->post('version');

				// make a backup
				copy(TL_ROOT . '/' . $configPathname, TL_ROOT . '/' . $configPathname . '~');

				// update requires
				$json   = new JsonFile(TL_ROOT . '/' . $configPathname);
				$config = $json->read();
				if (!array_key_exists('require', $config)) {
					$config['require'] = array();
				}
				$config['require'][$packageName] = $version;
				$json->write($config);

				$_SESSION['TL_INFO'][] = sprintf(
					$GLOBALS['TL_LANG']['composer_client']['added_candidate'],
					$packageName,
					$version
				);

				$_SESSION['COMPOSER_OUTPUT'] = $io->getOutput();
				$this->redirect('contao/main.php?do=composer');
			}

			$installationCandidates = $this->searchPackage($composer, $packageName, $input->get('cache') == 'no');

			if (empty($installationCandidates)) {
				$_SESSION['TL_ERROR'][] = sprintf(
					$GLOBALS['TL_LANG']['composer_client']['noInstallationCandidates'],
					$packageName
				);

				$_SESSION['COMPOSER_OUTPUT'] = $io->getOutput();
				$this->redirect('contao/main.php?do=composer');
			}

			$this->Template->setName('be_composer_client_install');
			$this->Template->packageName = $packageName;
			$this->Template->candidates  = $installationCandidates;
			return;
		}

		if ($input->post('update') == 'packages') {
			$lockPathname = preg_replace('#\.json$#', '.lock', $configPathname);

			$composer
				->getDownloadManager()
				->setOutputProgress(false);
			$installer = Installer::create($io, $composer);

			if (file_exists(TL_ROOT . '/' . $lockPathname)) {
				$installer->setUpdate(true);
			}

			$installer->run();

			$_SESSION['COMPOSER_OUTPUT'] = $io->getOutput();
			$this->reload();
		}

		/**
		 * Remove package
		 */
		if ($input->post('remove')) {
			$removeName = $input->post('remove');

			// make a backup
			copy(TL_ROOT . '/' . $configPathname, TL_ROOT . '/' . $configPathname . '~');

			// update requires
			$json   = new JsonFile(TL_ROOT . '/' . $configPathname);
			$config = $json->read();
			if (!array_key_exists('require', $config)) {
				$config['require'] = array();
			}
			unset($config['require'][$removeName]);
			$json->write($config);

			$_SESSION['TL_INFO'][] = sprintf(
				$GLOBALS['TL_LANG']['composer_client']['removeCandidate'],
				$removeName
			);

			$_SESSION['COMPOSER_OUTPUT'] = $io->getOutput();
			$this->redirect('contao/main.php?do=composer');
		}

		// update contao version if needed
		/** @var \Composer\Package\RootPackage $package */
		$package       = $composer->getPackage();
		$versionParser = new VersionParser();
		$version       = VERSION . (is_numeric(BUILD) ? '.' . BUILD : '-' . BUILD);
		$prettyVersion = $versionParser->normalize($version);
		if ($package->getVersion() !== $prettyVersion) {
			$configFile            = new JsonFile(TL_ROOT . '/' . $configPathname);
			$configJson            = $configFile->read();
			$configJson['version'] = $version;
			$configFile->write($configJson);

			$_SESSION['COMPOSER_OUTPUT'] = $io->getOutput();
			$this->reload();
		}

		// calculate dependency graph
		$dependencyGraph = $this->calculateDependencyGraph(
			$composer
				->getRepositoryManager()
				->getLocalRepository()
		);

		$this->Template->dependencyGraph = $dependencyGraph;
		$this->Template->output          = $_SESSION['COMPOSER_OUTPUT'];

		unset($_SESSION['COMPOSER_OUTPUT']);

		chdir(TL_ROOT);
	}

	protected function updateComposer()
	{
		$url = 'https://getcomposer.org/composer.phar';

		try {
			$this->download($url, TL_ROOT . '/composer/composer.phar');
			$_SESSION['TL_CONFIRM'][] = $GLOBALS['TL_LANG']['composer_client']['composerUpdated'];
			return true;
		}
		catch (Exception $e) {
			$this->log($e->getMessage() . "\n" . $e->getTraceAsString(), 'ComposerClient updateComposer', 'TL_ERROR');
			$_SESSION['TL_ERROR'][] = $e->getMessage();
			return false;
		}
	}

	protected function readComposerDevWarningTime()
	{
		$configPathname = new File('composer/composer.phar');
		$buffer         = '';
		do {
			$buffer .= fread($configPathname->handle, 1024);
		} while (!preg_match('#define\(\'COMPOSER_DEV_WARNING_TIME\',\s*(\d+)\);#', $buffer, $matches) && !feof(
			$configPathname->handle
		));
		if ($matches[1]) {
			return (int) $matches[1];
		}
		return false;
	}

	protected function increaseMemoryLimit()
	{
		/**
		 * Copyright (c) 2011 Nils Adermann, Jordi Boggiano
		 *
		 * @see https://github.com/composer/composer/blob/master/bin/composer
		 */
		if (function_exists('ini_set')) {
			@ini_set('display_errors', 1);

			$memoryInBytes = function ($value) {
				$unit  = strtolower(substr($value, -1, 1));
				$value = (int) $value;
				switch ($unit) {
					case 'g':
						$value *= 1024;
					// no break (cumulative multiplier)
					case 'm':
						$value *= 1024;
					// no break (cumulative multiplier)
					case 'k':
						$value *= 1024;
				}

				return $value;
			};

			$memoryLimit = trim(ini_get('memory_limit'));
			// Increase memory_limit if it is lower than 512M
			if ($memoryLimit != -1 && $memoryInBytes($memoryLimit) < 512 * 1024 * 1024) {
				@ini_set('memory_limit', '512M');
			}
			unset($memoryInBytes, $memoryLimit);
		}
	}

	protected function searchPackages(Composer $composer, array $tokens, $noCache = false)
	{
		$cache = FileCache::getInstance('composer');

		sort($tokens);
		$cacheKey = 'search:' . implode(',', $tokens);

		if (!$cache->$cacheKey || time() >= $cache->$cacheKey->ttl || $noCache) {
			$localRepository       = $composer
				->getRepositoryManager()
				->getLocalRepository();
			$platformRepository    = new PlatformRepository();
			$installedRepositories = new CompositeRepository(
				array(
					$localRepository,
					$platformRepository
				)
			);
			$repositories = array_merge(
				array($installedRepositories),
				$composer
					->getRepositoryManager()
					->getRepositories()
			);

			// remove packagist.org repository from composition
			$packagistRepository = null;
			$packagistBaseUrl = null;
			/** @var \Composer\Repository\ComposerRepository $repository */
			foreach ($repositories as $index => $repository) {
				if ($repository instanceof ComposerRepository) {
					$class = new ReflectionClass($repository);
					$urlProperty = $class->getProperty('baseUrl');
					$urlProperty->setAccessible(true);
					$url = $urlProperty->getValue($repository);

					if (preg_match('#^https?://packagist\.org#', $url)) {
						$packagistRepository = $repository;
						$packagistBaseUrl = $url;
						unset($repositories[$index]);
						break;
					}
				}
			}

			$repositories = new CompositeRepository($repositories);

			$priorities = array();
			$packages   = array();

			$repositories->filterPackages(
				function (PackageInterface $package) use ($tokens, &$priorities, &$packages) {
					if ($package instanceof AliasPackage ||
						isset($packages[$package->getName()])
					) {
						return;
					}

					/** @var \Composer\Package\CompletePackage $package */

					$priority = $this->calculatePriority(
						$tokens,
						$package->getName(),
						array(),
						$package->getDescription()
					);
					if ($priority) {
						$priorities[$package->getName()] = $priority;
						$packages[$package->getName()]   = array(
							'name'        => $package->getPrettyName(),
							'description' => $package->getDescription()
						);
					}
				},
				'Composer\Package\CompletePackage'
			);

			// quick search on packagist.org
			if ($packagistRepository && $packagistBaseUrl) {
				$url = $packagistBaseUrl . '/search.json?q=' . rawurlencode(implode(' ', $tokens));
				do {
					$jsonString = $this->download($url);
					$json = json_decode($jsonString, true);

					if (isset($json['results'])) {
						foreach ($json['results'] as $packageArray) {
							$packages[$packageArray['name']]   = $packageArray;
							$priorities[$packageArray['name']] = $this->calculatePriority(
								$tokens,
								$packageArray['name'],
								array(),
								$packageArray['description']
							);
						}
					}
					if (isset($json['next'])) {
						$url = $json['next'];
					}
					else {
						$url = false;
					}
				} while ($url);
			}

			usort(
				$packages,
				function (array $packageA, array $packageB) use ($priorities) {
					$priorityA = $priorities[$packageA['name']];
					$priorityB = $priorities[$packageB['name']];

					if ($priorityA == $priorityB) {
						return strcasecmp($packageA['name'], $packageB['name']);
					}
					else {
						return $priorityB - $priorityA;
					}
				}
			);

			$cache->$cacheKey = (object) array(
				'value' => $packages,
				'ttl'   => time() + 1800
			);
			return $packages;
		}

		return $cache->$cacheKey->value;
	}

	protected function calculatePriority(array $tokens, $name, array $keywords, $description)
	{
		$priority = 0;
		list($vendor, $name) = explode('/', $name, 2);
		foreach ($tokens as $token) {
			if (false !== stripos($vendor, $token)) {
				$priority += 10;
			}
			if (false !== stripos($name, $token)) {
				$priority += 5;
			}
			if (false !== stripos(implode(',', $keywords ? : array()), $token)) {
				$priority += 3;
			}
			if (false !== stripos($description, $token)) {
				$priority += 1;
			}
		}
		return $priority;
	}

	protected function searchPackage(Composer $composer, $packageName, $noCache = false)
	{
		$cache = FileCache::getInstance('composer');

		$cacheKey = 'package:' . $packageName;

		if (!$cache->$cacheKey || time() >= $cache->$cacheKey->ttl || $noCache) {
			// collect repositories
			$localRepository       = $composer
				->getRepositoryManager()
				->getLocalRepository();
			$platformRepository    = new PlatformRepository();
			$installedRepositories = new CompositeRepository(
				array(
					$localRepository,
					$platformRepository
				)
			);
			$repositories = array_merge(
				array($installedRepositories),
				$composer
					->getRepositoryManager()
					->getRepositories()
			);

			// remove packagist.org repository from composition
			$packagistRepository = null;
			$packagistBaseUrl = null;
			/** @var \Composer\Repository\ComposerRepository $repository */
			foreach ($repositories as $index => $repository) {
				if ($repository instanceof ComposerRepository) {
					$class = new ReflectionClass($repository);
					$urlProperty = $class->getProperty('baseUrl');
					$urlProperty->setAccessible(true);
					$url = $urlProperty->getValue($repository);

					if (preg_match('#^https?://packagist\.org#', $url)) {
						$packagistRepository = $repository;
						$packagistBaseUrl = $url;
						unset($repositories[$index]);
						break;
					}
				}
			}

			$repositories = new CompositeRepository($repositories);

			$versions = array();

			$repositories->filterPackages(
				function (PackageInterface $package) use ($packageName, &$versions) {
					if ($package instanceof AliasPackage) {
						return;
					}
					if ($package->getName() == $packageName) {
						$versions[$package->getVersion() . '@' . $package->getStability()] = $package;
					}
				},
				'Composer\Package\CompletePackage'
			);

			// quick search on packagist.org
			if ($packagistRepository && $packagistBaseUrl) {
				$jsonString = $this->download($packagistBaseUrl . '/packages/' . $packageName . '.json');
				$json = json_decode($jsonString, true);

				if (isset($json['package']) && isset($json['package']['versions'])) {
					foreach ($json['package']['versions'] as $packageArray) {
						$package = $packagistRepository->loadPackage(array('raw' => $packageArray));
						if ($package->getName() == $packageName) {
							$versions[$package->getVersion() . '@' . $package->getStability()] = $package;
						}
					}
				}
			}

			usort(
				$versions,
				function (PackageInterface $packageA, PackageInterface $packageB) {
					return $packageB
						->getReleaseDate()
						->getTimestamp() - $packageA
						->getReleaseDate()
						->getTimestamp();
					/*
					$versionA = $this->reformatVersion($packageA);
					$versionB = $this->reformatVersion($packageB);

					$classicA = preg_match('#^\d(\.\d+)*$#', $versionA);
					$classicB = preg_match('#^\d(\.\d+)*$#', $versionB);

					$branchA = 'dev-' == substr($packageA->getPrettyVersion(), 0, 4);
					$branchB = 'dev-' == substr($packageB->getPrettyVersion(), 0, 4);

					if ($branchA && $branchB) {
						return strcasecmp($branchA, $branchB);
					}
					if ($classicA && $classicB) {
						if ($packageA->getPrettyVersion() == 'dev-master') {
							return -1;
						}
						if ($packageB->getPrettyVersion() == 'dev-master') {
							return 1;
						}
						return version_compare($versionB, $versionA);
					}
					if ($classicA) {
						return -1;
					}
					if ($classicB) {
						return 1;
					}
					return 0;
					*/
				}
			);

			$cache->$cacheKey = (object) array(
				'value' => $versions,
				'ttl'   => time() + 1800
			);
			return $versions;
		}

		return $cache->$cacheKey->value;
	}

	protected function reformatVersion(PackageInterface $package)
	{
		$version = $package->getVersion();

		if (
			preg_match(
				'#^(.*?)[._-]?(stable|RC|beta|alpha|dev)(\d+)?$#',
				$version,
				$matches
			)
		) {
			$stability = VersionParser::normalizeStability($matches[2]);
			$version   = $matches[1] . '.' . (100 - BasePackage::$stabilities[$stability]);

			if ($matches[3]) {
				$version .= '.' . $matches[3];
			}
		}
		else {
			$version .= '.100';
		}

		return $version;
	}

	protected function calculateDependencyGraph(RepositoryInterface $repository)
	{
		$dependencyGraph = array();

		/** @var \Composer\Package\PackageInterface $package */
		foreach ($repository->getPackages() as $package) {
			$this->fillDependencyGraph($repository, $package, $dependencyGraph);
		}

		return $dependencyGraph;
	}

	protected function fillDependencyGraph(
		RepositoryInterface $repository,
		PackageInterface $package,
		array &$dependencyGraph
	) {
		/** @var string $requireName */
		/** @var \Composer\Package\Link $requireLink */
		foreach ($package->getRequires() as $requireName => $requireLink) {
			$dependencyGraph[$requireLink->getTarget()][$package->getName()] = $requireLink->getPrettyConstraint();
		}
	}

	protected function download($url, $file = false)
	{
		$return = null;

		if ($file === false) {
			$return = true;
			$file = 'php://temp';
		}

		$curl = curl_init($url);

		$headerStream = fopen('php://temp', 'wb+');
		$fileStream   = fopen($file, 'wb+');

		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
		curl_setopt($curl, CURLOPT_WRITEHEADER, $headerStream);
		curl_setopt($curl, CURLOPT_FILE, $fileStream);

		curl_exec($curl);

		rewind($headerStream);
		$header = stream_get_contents($headerStream);

		if ($return) {
			rewind($fileStream);
			$return = stream_get_contents($fileStream);
		}

		fclose($headerStream);
		fclose($fileStream);

		if (curl_errno($curl)) {
			throw new Exception(
				curl_error($curl),
				curl_errno($curl)
			);
		}

		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if ($code == 301 || $code == 302) {
			preg_match('/Location:(.*?)\n/', $header, $matches);
			$url = trim(array_pop($matches));

			return $this->download($url, $file);
		}

		return $return;
	}
}
