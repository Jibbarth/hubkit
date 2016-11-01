<?php

declare(strict_types=1);

/*
 * This file is part of the HubKit package.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace HubKit\Cli;

use HubKit\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Webmozart\Console\Adapter\ArgsInput;
use Webmozart\Console\Adapter\IOOutput;
use Webmozart\Console\Api\Args\Format\Argument;
use Webmozart\Console\Api\Args\Format\Option;
use Webmozart\Console\Api\Event\ConsoleEvents;
use Webmozart\Console\Api\Event\PreHandleEvent;
use Webmozart\Console\Api\Formatter\Style;
use Webmozart\Console\Config\DefaultApplicationConfig;

final class HubKitApplicationConfig extends DefaultApplicationConfig
{
    /**
     * The version of the Application.
     */
    const VERSION = '@package_version@';

    /**
     * @var Container
     */
    private $container;

    /**
     * Creates the configuration.
     *
     * @param Container $container The service container (only to be injected during tests)
     */
    public function __construct(Container $container = null)
    {
        if (null === $container) {
            if (!file_exists(__DIR__.'/../../config.php') && file_exists(__DIR__.'/../../config.php.dist')) {
                throw new \InvalidArgumentException(
                    sprintf('Please copy "%s.dist" to "%$1s" and change the API token.', __DIR__.'/../../config.php')
                );
            }

            $parameters = [];
            $parameters['current_dir'] = getcwd().'/';
            $parameters['config_dir'] = realpath(__DIR__.'/../../config.php');

            $container = new Container($parameters);
        }

        $this->container = $container;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setEventDispatcher(new EventDispatcher());

        parent::configure();

        $this
            ->setName('hubkit')
            ->setDisplayName('HubKit')

            ->setVersion(self::VERSION)
            ->setDebug('true' === getenv('HUBKIT_DEBUG'))
            ->addStyle(Style::tag('good')->fgGreen())
            ->addStyle(Style::tag('bad')->fgRed())
            ->addStyle(Style::tag('warn')->fgYellow())
            ->addStyle(Style::tag('hl')->fgGreen())
        ;

        $this->addEventListener(
            ConsoleEvents::PRE_HANDLE,
            function (PreHandleEvent $event) {
                // Set-up the IO for the Symfony Helper classes.
                if (!isset($this->container['console_io'])) {
                    $io = $event->getIO();
                    $args = $event->getArgs();

                    $input = new ArgsInput($args->getRawArgs(), $args);
                    $input->setInteractive($io->isInteractive());

                    $this->container['console_io'] = $io;
                    $this->container['console_args'] = $args;
                    $this->container['sf.console_input'] = $input;
                    $this->container['sf.console_output'] = new IOOutput($io);
                }
            }
        );

        $this->addEventListener(
            ConsoleEvents::PRE_HANDLE,
            function (PreHandleEvent $event) {
                // Set-up the IO for the Symfony Helper classes.
                if (!isset($this->container['console_io'])) {
                    $io = $event->getIO();
                    $args = $event->getArgs();

                    $input = new ArgsInput($args->getRawArgs(), $args);
                    $input->setInteractive($io->isInteractive());

                    $this->container['console_io'] = $io;
                    $this->container['console_args'] = $args;
                    $this->container['sf.console_input'] = $input;
                    $this->container['sf.console_output'] = new IOOutput($io);
                }
            }
        );

        $this->addEventListener(
            ConsoleEvents::PRE_HANDLE,
            function (PreHandleEvent $event) {
                if (in_array($name = $event->getCommand()->getName(), ['diagnose', 'help'], true)) {
                    return;
                }

                if (!$this->container['git']->isGitDir()) {
                    throw new \RuntimeException(
                        sprintf('Command "%s" can only be executed from the root of a Git repository.', $name)
                    );
                }
            }
        );

        $this
            ->beginCommand('pull-request')
                ->setDescription('Pull-request management')
                ->addArgument('profile', Argument::OPTIONAL, 'The name of the profile')
                ->addOption('all', null, Option::BOOLEAN, 'Ask all questions (including optional)')
//                ->addOption('dry-run', null, Option::BOOLEAN, 'Show what would have been executed, without actually executing')
//                ->setHandler(function () {
//                    return new Handler\GenerateCommandHandler(
//                        $this->container['style'],
//                        $this->container['config'],
//                        $this->container['profile_config_resolver'],
//                        $this->container['answers_set_factory']
//                    );
//                })
            ->end()

            ->beginCommand('profile')
                ->setDescription('Manage the profiles of your project')
                ->setHandler(function () {
                    return new Handler\ProfileCommandHandler(
                        $this->container['style'],
                        $this->container['profile_config_resolver'],
                        $this->container['config']
                    );
                })

                ->beginSubCommand('list')
                    ->setHandlerMethod('handleList')
                    ->markDefault()
                ->end()

                ->beginSubCommand('show')
                    ->addArgument('name', Argument::OPTIONAL, 'The name of the profile')
                    ->setHandlerMethod('handleShow')
                ->end()
            ->end()
        ;
    }
}
