<?php declare(strict_types = 1);

namespace Contributte\Console\DI;

use Contributte\Console\Application;
use Contributte\Console\CommandLoader\ContainerCommandLoader;
use Contributte\Console\Exception\Logical\InvalidArgumentException;
use Nette\DI\CompilerExtension;
use Nette\DI\MissingServiceException;
use Nette\DI\ServiceCreationException;
use Nette\DI\ServiceDefinition;
use Nette\DI\Statement;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Utils\Arrays;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @property-read stdClass $config
 */
class ConsoleExtension extends CompilerExtension
{

	public const COMMAND_TAG = 'console.command';

	/** @var bool */
	private $cliMode;

	public $defaults = [
		'url' => null,
		'name' => 'NetteApp',
		'version' => 1,
		'catchExceptions' => null,
		'autoExit' => null,
		'lazy' => true,
	];

	public function __construct(bool $cliMode = false)
	{
		if (func_num_args() <= 0) {
			throw new InvalidArgumentException(sprintf('Provide CLI mode, e.q. %s(%%consoleMode%%).', self::class));
		}

		$this->cliMode = $cliMode;
	}

	/**
	 * Register services
	 */
	public function loadConfiguration(): void
	{
		// Skip if isn't CLI
		if ($this->cliMode !== true) {
			return;
		}

		$builder = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		// Register Symfony Console Application
		$applicationDef = $builder->addDefinition($this->prefix('application'))
			->setFactory(Application::class);

		// Setup console name
		if ($config['name'] !== null) {
			$applicationDef->addSetup('setName', [$config['name']]);
		}

		// Setup console version
		if ($config['version'] !== null) {
			$applicationDef->addSetup('setVersion', [(string) $config['version']]);
		}

		// Catch or populate exceptions
		if ($config['catchExceptions'] !== null) {
			$applicationDef->addSetup('setCatchExceptions', [$config['catchExceptions']]);
		}

		// Call die() or not
		if ($config['autoExit'] !== null) {
			$applicationDef->addSetup('setAutoExit', [$config['autoExit']]);
		}

		// Commands lazy loading
		if ($config['lazy']) {
			$builder->addDefinition($this->prefix('commandLoader'))
				->setType(CommandLoaderInterface::class)
				->setFactory(ContainerCommandLoader::class);

			$applicationDef->addSetup('setCommandLoader', ['@' . $this->prefix('commandLoader')]);
		}

	}

	/**
	 * Decorate services
	 */
	public function beforeCompile(): void
	{
		// Skip if isn't CLI
		if ($this->cliMode !== true) {
			return;
		}

		$builder = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		/** @var ServiceDefinition $applicationDef */
		$applicationDef = $builder->getDefinition($this->prefix('application'));

		// Setup URL for CLI
		if ($config['url'] !== null && $builder->hasDefinition('http.request')) {
			/** @var ServiceDefinition $httpDef */
			$httpDef = $builder->getDefinition('http.request');
			$httpDef->setFactory(Request::class, [new Statement(UrlScript::class, [$config['url']])]);
		}

		// Register all commands (if they are not lazy-loaded)
		// otherwise build a command map for command loader
		$commands = $builder->findByType(Command::class);

		if (!$config['lazy']) {
			// Iterate over all commands and add to console
			foreach ($commands as $serviceName => $service) {
				$applicationDef->addSetup('add', [$service]);
			}
		} else {
			$commandMap = [];

			// Iterate over all commands and build commandMap
			foreach ($commands as $serviceName => $service) {
				$tags = $service->getTags();
				$entry = ['name' => null, 'alias' => null];

				if (isset($tags[self::COMMAND_TAG])) {
					// Parse tag's name attribute
					if (is_string($tags[self::COMMAND_TAG])) {
						$entry['name'] = $tags[self::COMMAND_TAG];
					} elseif (is_array($tags[self::COMMAND_TAG])) {
						$entry['name'] = Arrays::get($tags[self::COMMAND_TAG], 'name', null);
					}
				} else {
					// Parse it from static property
					$entry['name'] = call_user_func([$service->getType(), 'getDefaultName']);
				}

				// Validate command name
				if (!isset($entry['name'])) {
					throw new ServiceCreationException(
						sprintf(
							'Command "%s" missing tag "%s[name]" or variable "$defaultName".',
							$service->getType(),
							self::COMMAND_TAG
						)
					);
				}

				// Append service to command map
				$commandMap[$entry['name']] = $serviceName;
			}

			/** @var ServiceDefinition $commandLoaderDef */
			$commandLoaderDef = $builder->getDefinition($this->prefix('commandLoader'));
			$commandLoaderDef->getFactory()->arguments = ['@container', $commandMap];
		}

		// Register event dispatcher, if available
		try {
			$dispatcherDef = $builder->getDefinitionByType(EventDispatcherInterface::class);
			$applicationDef->addSetup('setDispatcher', [$dispatcherDef]);
		} catch (MissingServiceException $e) {
			// Event dispatcher is not installed, ignore
		}
	}

}
