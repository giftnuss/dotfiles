#!/usr/bin/env php
<?php
/**
 * Extract contents of a phar archive to a given directory
 *
 * This file is part of the PharUtil library.
 * @author Krzysztof Kotowicz <kkotowicz at gmail dot com>
 * @package PharUtil
 */

// Include the Console_CommandLine package.

require_once 'PEAR/Exception.php';

class Console_CommandLine {
    public static $errors = array('option_bad_name' => 'option name must be a valid php variable name (got: {$name})', 'argument_bad_name' => 'argument name must be a valid php variable name (got: {$name})', 'option_long_and_short_name_missing' => 'you must provide at least an option short name or long name for option "{$name}"', 'option_bad_short_name' => 'option "{$name}" short name must be a dash followed by a letter (got: "{$short_name}")', 'option_bad_long_name' => 'option "{$name}" long name must be 2 dashes followed by a word (got: "{$long_name}")', 'option_unregistered_action' => 'unregistered action "{$action}" for option "{$name}".', 'option_bad_action' => 'invalid action for option "{$name}".', 'option_invalid_callback' => 'you must provide a valid callback for option "{$name}"', 'action_class_does_not_exists' => 'action "{$name}" class "{$class}" not found, make sure that your class is available before calling Console_CommandLine::registerAction()', 'invalid_xml_file' => 'XML definition file "{$file}" does not exists or is not readable', 'invalid_rng_file' => 'RNG file "{$file}" does not exists or is not readable');
    public $name;
    public $description = '';
    public $version = '';
    public $add_help_option = true;
    public $add_version_option = true;
    public $renderer = false;
    public $outputter = false;
    public $message_provider = false;
    public $force_posix = false;
    public $force_options_defaults = false;
    public $options = array();
    public $args = array();
    public $commands = array();
    public $parent = false;
    public static $actions = array('StoreTrue' => array('Console_CommandLine_Action_StoreTrue', true), 'StoreFalse' => array('Console_CommandLine_Action_StoreFalse', true), 'StoreString' => array('Console_CommandLine_Action_StoreString', true), 'StoreInt' => array('Console_CommandLine_Action_StoreInt', true), 'StoreFloat' => array('Console_CommandLine_Action_StoreFloat', true), 'StoreArray' => array('Console_CommandLine_Action_StoreArray', true), 'Callback' => array('Console_CommandLine_Action_Callback', true), 'Counter' => array('Console_CommandLine_Action_Counter', true), 'Help' => array('Console_CommandLine_Action_Help', true), 'Version' => array('Console_CommandLine_Action_Version', true), 'Password' => array('Console_CommandLine_Action_Password', true), 'List' => array('Console_CommandLine_Action_List', true),);
    public $messages = array();
    private $_dispatchLater = array();
    public function __construct(array $params = array()) {
        if (isset($params['name'])) {
            $this->name = $params['name'];
        } else if (isset($argv) && count($argv) > 0) {
            $this->name = $argv[0];
        } else if (isset($_SERVER['argv']) && count($_SERVER['argv']) > 0) {
            $this->name = $_SERVER['argv'][0];
        } else if (isset($_SERVER['SCRIPT_NAME'])) {
            $this->name = basename($_SERVER['SCRIPT_NAME']);
        }
        if (isset($params['description'])) {
            $this->description = $params['description'];
        }
        if (isset($params['version'])) {
            $this->version = $params['version'];
        }
        if (isset($params['add_version_option'])) {
            $this->add_version_option = $params['add_version_option'];
        }
        if (isset($params['add_help_option'])) {
            $this->add_help_option = $params['add_help_option'];
        }
        if (isset($params['force_posix'])) {
            $this->force_posix = $params['force_posix'];
        } else if (getenv('POSIXLY_CORRECT')) {
            $this->force_posix = true;
        }
        if (isset($params['messages']) && is_array($params['messages'])) {
            $this->messages = $params['messages'];
        }
        $this->renderer = new Console_CommandLine_Renderer_Default($this);
        $this->outputter = new Console_CommandLine_Outputter_Default();
        $this->message_provider = new Console_CommandLine_MessageProvider_Default();
    }
    public function accept($instance) {
        if ($instance instanceof Console_CommandLine_Renderer) {
            if (property_exists($instance, 'parser') && !$instance->parser) {
                $instance->parser = $this;
            }
            $this->renderer = $instance;
        } else if ($instance instanceof Console_CommandLine_Outputter) {
            $this->outputter = $instance;
        } else if ($instance instanceof Console_CommandLine_MessageProvider) {
            $this->message_provider = $instance;
        } else {
            throw Console_CommandLine_Exception::factory('INVALID_CUSTOM_INSTANCE', array(), $this, $this->messages);
        }
    }
    public static function fromXmlFile($file) {
        return Console_CommandLine_XmlParser::parse($file);
    }
    public static function fromXmlString($string) {
        return Console_CommandLine_XmlParser::parseString($string);
    }
    public function addArgument($name, $params = array()) {
        if ($name instanceof Console_CommandLine_Argument) {
            $argument = $name;
        } else {
            $argument = new Console_CommandLine_Argument($name, $params);
        }
        $argument->validate();
        $this->args[$argument->name] = $argument;
        return $argument;
    }
    public function addCommand($name, $params = array()) {
        if ($name instanceof Console_CommandLine_Command) {
            $command = $name;
        } else {
            $params['name'] = $name;
            $command = new Console_CommandLine_Command($params);
            $cascade = array('add_help_option', 'add_version_option', 'outputter', 'message_provider', 'force_posix', 'force_options_defaults');
            foreach ($cascade as $property) {
                if (!isset($params[$property])) {
                    $command->$property = $this->$property;
                }
            }
            if (!isset($params['renderer'])) {
                $renderer = clone $this->renderer;
                $renderer->parser = $command;
                $command->renderer = $renderer;
            }
        }
        $command->parent = $this;
        $this->commands[$command->name] = $command;
        return $command;
    }
    public function addOption($name, $params = array()) {
        if ($name instanceof Console_CommandLine_Option) {
            $opt = $name;
        } else {
            $opt = new Console_CommandLine_Option($name, $params);
        }
        $opt->validate();
        if ($this->force_options_defaults) {
            $opt->setDefaults();
        }
        $this->options[$opt->name] = $opt;
        if (!empty($opt->choices) && $opt->add_list_option) {
            $this->addOption('list_' . $opt->name, array('long_name' => '--list-' . $opt->name, 'description' => $this->message_provider->get('LIST_OPTION_MESSAGE', array('name' => $opt->name)), 'action' => 'List', 'action_params' => array('list' => $opt->choices),));
        }
        return $opt;
    }
    public function displayError($error, $exitCode = 1) {
        $this->outputter->stderr($this->renderer->error($error));
        if ($exitCode !== false) {
            exit($exitCode);
        }
    }
    public function displayUsage($exitCode = 0) {
        $this->outputter->stdout($this->renderer->usage());
        if ($exitCode !== false) {
            exit($exitCode);
        }
    }
    public function displayVersion($exitCode = 0) {
        $this->outputter->stdout($this->renderer->version());
        if ($exitCode !== false) {
            exit($exitCode);
        }
    }
    public function findOption($str) {
        $str = trim($str);
        if ($str === '') {
            return false;
        }
        $matches = array();
        foreach ($this->options as $opt) {
            if ($opt->short_name == $str || $opt->long_name == $str || $opt->name == $str) {
                return $opt;
            }
            if (substr($opt->long_name, 0, strlen($str)) === $str) {
                $matches[] = $opt;
            }
        }
        if ($count = count($matches)) {
            if ($count > 1) {
                $matches_str = '';
                $padding = '';
                foreach ($matches as $opt) {
                    $matches_str.= $padding . $opt->long_name;
                    $padding = ', ';
                }
                throw Console_CommandLine_Exception::factory('OPTION_AMBIGUOUS', array('name' => $str, 'matches' => $matches_str), $this, $this->messages);
            }
            return $matches[0];
        }
        return false;
    }
    public static function registerAction($name, $class) {
        if (!isset(self::$actions[$name])) {
            if (!class_exists($class)) {
                self::triggerError('action_class_does_not_exists', E_USER_ERROR, array('{$name}' => $name, '{$class}' => $class));
            }
            self::$actions[$name] = array($class, false);
        }
    }
    public static function triggerError($msgId, $level, $params = array()) {
        if (isset(self::$errors[$msgId])) {
            $msg = str_replace(array_keys($params), array_values($params), self::$errors[$msgId]);
            trigger_error($msg, $level);
        } else {
            trigger_error('unknown error', $level);
        }
    }
    public function parse($userArgc = null, $userArgv = null) {
        $this->addBuiltinOptions();
        if ($userArgc !== null && $userArgv !== null) {
            $argc = $userArgc;
            $argv = $userArgv;
        } else {
            list($argc, $argv) = $this->getArgcArgv();
        }
        $result = new Console_CommandLine_Result();
        if (!($this instanceof Console_CommandLine_Command)) {
            array_shift($argv);
            $argc--;
        }
        $args = array();
        foreach ($this->options as $name => $option) {
            $result->options[$name] = $option->default;
        }
        while ($argc--) {
            $token = array_shift($argv);
            try {
                if ($cmd = $this->_getSubCommand($token)) {
                    $result->command_name = $cmd->name;
                    $result->command = $cmd->parse($argc, $argv);
                    break;
                } else {
                    $this->parseToken($token, $result, $args, $argc);
                }
            }
            catch(Exception $exc) {
                throw $exc;
            }
        }
        $this->parseToken(null, $result, $args, 0);
        if (count($this->commands) > 0 && count($this->args) === 0 && count($args) > 0) {
            throw Console_CommandLine_Exception::factory('INVALID_SUBCOMMAND', array('command' => $args[0]), $this, $this->messages);
        }
        $argnum = 0;
        foreach ($this->args as $name => $arg) {
            if (!$arg->optional) {
                $argnum++;
            }
        }
        if (count($args) < $argnum) {
            throw Console_CommandLine_Exception::factory('ARGUMENT_REQUIRED', array('argnum' => $argnum, 'plural' => $argnum > 1 ? 's' : ''), $this, $this->messages);
        }
        $c = count($this->args);
        foreach ($this->args as $name => $arg) {
            $c--;
            if ($arg->multiple) {
                $result->args[$name] = $c ? array_splice($args, 0, -$c) : $args;
            } else {
                $result->args[$name] = array_shift($args);
            }
        }
        foreach ($this->_dispatchLater as $optArray) {
            $optArray[0]->dispatchAction($optArray[1], $optArray[2], $this);
        }
        return $result;
    }
    protected function parseToken($token, $result, &$args, $argc) {
        static $lastopt = false;
        static $stopflag = false;
        $last = $argc === 0;
        if (!$stopflag && $lastopt) {
            if (substr($token, 0, 1) == '-') {
                if ($lastopt->argument_optional) {
                    $this->_dispatchAction($lastopt, '', $result);
                    if ($lastopt->action != 'StoreArray') {
                        $lastopt = false;
                    }
                } else if (isset($result->options[$lastopt->name])) {
                    $lastopt = false;
                } else {
                    throw Console_CommandLine_Exception::factory('OPTION_VALUE_REQUIRED', array('name' => $lastopt->name), $this, $this->messages);
                }
            } else {
                if ($lastopt->action == 'StoreArray' && !empty($result->options[$lastopt->name]) && count($this->args) > ($argc + count($args))) {
                    if (!is_null($token)) {
                        $args[] = $token;
                    }
                    return;
                }
                if (!is_null($token) || $lastopt->action == 'Password') {
                    $this->_dispatchAction($lastopt, $token, $result);
                }
                if ($lastopt->action != 'StoreArray') {
                    $lastopt = false;
                }
                return;
            }
        }
        if (!$stopflag && substr($token, 0, 2) == '--') {
            $optkv = explode('=', $token, 2);
            if (trim($optkv[0]) == '--') {
                $stopflag = true;
                return;
            }
            $opt = $this->findOption($optkv[0]);
            if (!$opt) {
                throw Console_CommandLine_Exception::factory('OPTION_UNKNOWN', array('name' => $optkv[0]), $this, $this->messages);
            }
            $value = isset($optkv[1]) ? $optkv[1] : false;
            if (!$opt->expectsArgument() && $value !== false) {
                throw Console_CommandLine_Exception::factory('OPTION_VALUE_UNEXPECTED', array('name' => $opt->name, 'value' => $value), $this, $this->messages);
            }
            if ($opt->expectsArgument() && $value === false) {
                if ($last && !$opt->argument_optional) {
                    throw Console_CommandLine_Exception::factory('OPTION_VALUE_REQUIRED', array('name' => $opt->name), $this, $this->messages);
                }
                $lastopt = $opt;
                return;
            }
            if ($opt->action == 'StoreArray') {
                $lastopt = $opt;
            }
            $this->_dispatchAction($opt, $value, $result);
        } else if (!$stopflag && substr($token, 0, 1) == '-') {
            $optname = substr($token, 0, 2);
            if ($optname == '-') {
                $args[] = file_get_contents('php://stdin');
                return;
            }
            $opt = $this->findOption($optname);
            if (!$opt) {
                throw Console_CommandLine_Exception::factory('OPTION_UNKNOWN', array('name' => $optname), $this, $this->messages);
            }
            $next = substr($token, 2, 1);
            if ($next === false) {
                if ($opt->expectsArgument()) {
                    if ($last && !$opt->argument_optional) {
                        throw Console_CommandLine_Exception::factory('OPTION_VALUE_REQUIRED', array('name' => $opt->name), $this, $this->messages);
                    }
                    $lastopt = $opt;
                    return;
                }
                $value = false;
            } else {
                if (!$opt->expectsArgument()) {
                    if ($nextopt = $this->findOption('-' . $next)) {
                        $this->_dispatchAction($opt, false, $result);
                        $this->parseToken('-' . substr($token, 2), $result, $args, $last);
                        return;
                    } else {
                        throw Console_CommandLine_Exception::factory('OPTION_UNKNOWN', array('name' => $next), $this, $this->messages);
                    }
                }
                if ($opt->action == 'StoreArray') {
                    $lastopt = $opt;
                }
                $value = substr($token, 2);
            }
            $this->_dispatchAction($opt, $value, $result);
        } else {
            if (!$stopflag && $this->force_posix) {
                $stopflag = true;
            }
            if (!is_null($token)) {
                $args[] = $token;
            }
        }
    }
    public function addBuiltinOptions() {
        if ($this->add_help_option) {
            $helpOptionParams = array('long_name' => '--help', 'description' => 'show this help message and exit', 'action' => 'Help');
            if (!($option = $this->findOption('-h')) || $option->action == 'Help') {
                $helpOptionParams['short_name'] = '-h';
            }
            $this->addOption('help', $helpOptionParams);
        }
        if ($this->add_version_option && !empty($this->version)) {
            $versionOptionParams = array('long_name' => '--version', 'description' => 'show the program version and exit', 'action' => 'Version');
            if (!$this->findOption('-v')) {
                $versionOptionParams['short_name'] = '-v';
            }
            $this->addOption('version', $versionOptionParams);
        }
    }
    protected function getArgcArgv() {
        if (php_sapi_name() != 'cli') {
            $argv = array($this->name);
            if (isset($_REQUEST)) {
                foreach ($_REQUEST as $key => $value) {
                    if (!is_array($value)) {
                        $value = array($value);
                    }
                    $opt = $this->findOption($key);
                    if ($opt instanceof Console_CommandLine_Option) {
                        $argv[] = $opt->short_name ? $opt->short_name : $opt->long_name;
                        foreach ($value as $v) {
                            if ($opt->expectsArgument()) {
                                $argv[] = isset($_REQUEST[$key]) ? urldecode($v) : $v;
                            } else if ($v == '0' || $v == 'false') {
                                array_pop($argv);
                            }
                        }
                    } else if (isset($this->args[$key])) {
                        foreach ($value as $v) {
                            $argv[] = isset($_REQUEST[$key]) ? urldecode($v) : $v;
                        }
                    }
                }
            }
            return array(count($argv), $argv);
        }
        if (isset($argc) && isset($argv)) {
            return array($argc, $argv);
        }
        if (isset($_SERVER['argc']) && isset($_SERVER['argv'])) {
            return array($_SERVER['argc'], $_SERVER['argv']);
        }
        return array(0, array());
    }
    private function _dispatchAction($option, $token, $result) {
        if ($option->action == 'Password') {
            $this->_dispatchLater[] = array($option, $token, $result);
        } else {
            $option->dispatchAction($token, $result, $this);
        }
    }
    private function _getSubCommand($token) {
        foreach ($this->commands as $cmd) {
            if ($cmd->name == $token || in_array($token, $cmd->aliases)) {
                return $cmd;
            }
        }
        return false;
    }
    
}

class Console_CommandLine_Renderer_Default implements Console_CommandLine_Renderer {
    public $line_width = 75;
    public $options_on_different_lines = false;
    public $parser = false;
    public function __construct($parser = false) {
        $this->parser = $parser;
    }
    public function usage() {
        $ret = '';
        if (!empty($this->parser->description)) {
            $ret.= $this->description() . "\n\n";
        }
        $ret.= $this->usageLine() . "\n";
        if (count($this->parser->commands) > 0) {
            $ret.= $this->commandUsageLine() . "\n";
        }
        if (count($this->parser->options) > 0) {
            $ret.= "\n" . $this->optionList() . "\n";
        }
        if (count($this->parser->args) > 0) {
            $ret.= "\n" . $this->argumentList() . "\n";
        }
        if (count($this->parser->commands) > 0) {
            $ret.= "\n" . $this->commandList() . "\n";
        }
        $ret.= "\n";
        return $ret;
    }
    public function error($error) {
        $ret = 'Error: ' . $error . "\n";
        if ($this->parser->add_help_option) {
            $name = $this->name();
            $ret.= $this->wrap($this->parser->message_provider->get('PROG_HELP_LINE', array('progname' => $name))) . "\n";
            if (count($this->parser->commands) > 0) {
                $ret.= $this->wrap($this->parser->message_provider->get('COMMAND_HELP_LINE', array('progname' => $name))) . "\n";
            }
        }
        return $ret;
    }
    public function version() {
        return $this->parser->message_provider->get('PROG_VERSION_LINE', array('progname' => $this->name(), 'version' => $this->parser->version)) . "\n";
    }
    protected function name() {
        $name = $this->parser->name;
        $parent = $this->parser->parent;
        while ($parent) {
            if (count($parent->options) > 0) {
                $name = '[' . strtolower($this->parser->message_provider->get('OPTION_WORD', array('plural' => 's'))) . '] ' . $name;
            }
            $name = $parent->name . ' ' . $name;
            $parent = $parent->parent;
        }
        return $this->wrap($name);
    }
    protected function description() {
        return $this->wrap($this->parser->description);
    }
    protected function usageLine() {
        $usage = $this->parser->message_provider->get('USAGE_WORD') . ":\n";
        $ret = $usage . '  ' . $this->name();
        if (count($this->parser->options) > 0) {
            $ret.= ' [' . strtolower($this->parser->message_provider->get('OPTION_WORD')) . ']';
        }
        if (count($this->parser->args) > 0) {
            foreach ($this->parser->args as $name => $arg) {
                $ret.= ' <' . $arg->help_name . ($arg->multiple ? '...' : '') . '>';
            }
        }
        return $this->columnWrap($ret, 2);
    }
    protected function commandUsageLine() {
        if (count($this->parser->commands) == 0) {
            return '';
        }
        $ret = '  ' . $this->name();
        if (count($this->parser->options) > 0) {
            $ret.= ' [' . strtolower($this->parser->message_provider->get('OPTION_WORD')) . ']';
        }
        $ret.= " <command>";
        $hasArgs = false;
        $hasOptions = false;
        foreach ($this->parser->commands as $command) {
            if (!$hasArgs && count($command->args) > 0) {
                $hasArgs = true;
            }
            if (!$hasOptions && ($command->add_help_option || $command->add_version_option || count($command->options) > 0)) {
                $hasOptions = true;
            }
        }
        if ($hasOptions) {
            $ret.= ' [options]';
        }
        if ($hasArgs) {
            $ret.= ' [args]';
        }
        return $this->columnWrap($ret, 2);
    }
    protected function argumentList() {
        $col = 0;
        $args = array();
        foreach ($this->parser->args as $arg) {
            $argstr = '  ' . $arg->toString();
            $args[] = array($argstr, $arg->description);
            $ln = strlen($argstr);
            if ($col < $ln) {
                $col = $ln;
            }
        }
        $ret = $this->parser->message_provider->get('ARGUMENT_WORD') . ":";
        foreach ($args as $arg) {
            $text = str_pad($arg[0], $col) . '  ' . $arg[1];
            $ret.= "\n" . $this->columnWrap($text, $col + 2);
        }
        return $ret;
    }
    protected function optionList() {
        $col = 0;
        $options = array();
        foreach ($this->parser->options as $option) {
            $delim = $this->options_on_different_lines ? "\n" : ', ';
            $optstr = $option->toString($delim);
            $lines = explode("\n", $optstr);
            $lines[0] = '  ' . $lines[0];
            if (count($lines) > 1) {
                $lines[1] = '  ' . $lines[1];
                $ln = strlen($lines[1]);
            } else {
                $ln = strlen($lines[0]);
            }
            $options[] = array($lines, $option->description);
            if ($col < $ln) {
                $col = $ln;
            }
        }
        $ret = $this->parser->message_provider->get('OPTION_WORD') . ":";
        foreach ($options as $option) {
            if (count($option[0]) > 1) {
                $text = str_pad($option[0][1], $col) . '  ' . $option[1];
                $pre = $option[0][0] . "\n";
            } else {
                $text = str_pad($option[0][0], $col) . '  ' . $option[1];
                $pre = '';
            }
            $ret.= "\n" . $pre . $this->columnWrap($text, $col + 2);
        }
        return $ret;
    }
    protected function commandList() {
        $commands = array();
        $col = 0;
        foreach ($this->parser->commands as $cmdname => $command) {
            $cmdname = '  ' . $cmdname;
            $commands[] = array($cmdname, $command->description, $command->aliases);
            $ln = strlen($cmdname);
            if ($col < $ln) {
                $col = $ln;
            }
        }
        $ret = $this->parser->message_provider->get('COMMAND_WORD') . ":";
        foreach ($commands as $command) {
            $text = str_pad($command[0], $col) . '  ' . $command[1];
            if ($aliasesCount = count($command[2])) {
                $pad = '';
                $text.= ' (';
                $text.= $aliasesCount > 1 ? 'aliases: ' : 'alias: ';
                foreach ($command[2] as $alias) {
                    $text.= $pad . $alias;
                    $pad = ', ';
                }
                $text.= ')';
            }
            $ret.= "\n" . $this->columnWrap($text, $col + 2);
        }
        return $ret;
    }
    protected function wrap($text, $lw = null) {
        if ($this->line_width > 0) {
            if ($lw === null) {
                $lw = $this->line_width;
            }
            return wordwrap($text, $lw, "\n", false);
        }
        return $text;
    }
    protected function columnWrap($text, $cw) {
        $tokens = explode("\n", $this->wrap($text));
        $ret = $tokens[0];
        $chunks = $this->wrap(trim(substr($text, strlen($ret))), $this->line_width - $cw);
        $tokens = explode("\n", $chunks);
        foreach ($tokens as $token) {
            if (!empty($token)) {
                $ret.= "\n" . str_repeat(' ', $cw) . $token;
            }
        }
        return $ret;
    }
    
}

interface Console_CommandLine_Renderer {
    public function usage();
    public function error($error);
    public function version();
    
}

interface Console_CommandLine_CustomMessageProvider {
    public function getWithCustomMessages($code, $vars = array(), $messages = array());
    
}

class Console_CommandLine_Option extends Console_CommandLine_Element {
    public $short_name;
    public $long_name;
    public $action = 'StoreString';
    public $default;
    public $choices = array();
    public $callback;
    public $action_params = array();
    public $argument_optional = false;
    public $add_list_option = false;
    private $_action_instance = null;
    public function __construct($name = null, $params = array()) {
        parent::__construct($name, $params);
        if ($this->action == 'Password') {
            $this->argument_optional = true;
        }
    }
    public function toString($delim = ", ") {
        $ret = '';
        $padding = '';
        if ($this->short_name != null) {
            $ret.= $this->short_name;
            if ($this->expectsArgument()) {
                $ret.= ' ' . $this->help_name;
            }
            $padding = $delim;
        }
        if ($this->long_name != null) {
            $ret.= $padding . $this->long_name;
            if ($this->expectsArgument()) {
                $ret.= '=' . $this->help_name;
            }
        }
        return $ret;
    }
    public function expectsArgument() {
        if ($this->action == 'StoreTrue' || $this->action == 'StoreFalse' || $this->action == 'Help' || $this->action == 'Version' || $this->action == 'Counter' || $this->action == 'List') {
            return false;
        }
        return true;
    }
    public function dispatchAction($value, $result, $parser) {
        $actionInfo = Console_CommandLine::$actions[$this->action];
        if (true === $actionInfo[1]) {
            $tokens = explode('_', $actionInfo[0]);
            //include_once implode('/', $tokens) . '.php';
        }
        $clsname = $actionInfo[0];
        if ($this->_action_instance === null) {
            $this->_action_instance = new $clsname($result, $this, $parser);
        }
        if (!empty($this->choices) && !in_array($this->_action_instance->format($value), $this->choices)) {
            throw Console_CommandLine_Exception::factory('OPTION_VALUE_NOT_VALID', array('name' => $this->name, 'choices' => implode('", "', $this->choices), 'value' => $value,), $parser, $this->messages);
        }
        $this->_action_instance->execute($value, $this->action_params);
    }
    public function validate() {
        if (!preg_match('/^[a-zA-Z_\x7f-\xff]+[a-zA-Z0-9_\x7f-\xff]*$/', $this->name)) {
            Console_CommandLine::triggerError('option_bad_name', E_USER_ERROR, array('{$name}' => $this->name));
        }
        parent::validate();
        if ($this->short_name == null && $this->long_name == null) {
            Console_CommandLine::triggerError('option_long_and_short_name_missing', E_USER_ERROR, array('{$name}' => $this->name));
        }
        if ($this->short_name != null && !(preg_match('/^\-[a-zA-Z]{1}$/', $this->short_name))) {
            Console_CommandLine::triggerError('option_bad_short_name', E_USER_ERROR, array('{$name}' => $this->name, '{$short_name}' => $this->short_name));
        }
        if ($this->long_name != null && !preg_match('/^\-\-[a-zA-Z]+[a-zA-Z0-9_\-]*$/', $this->long_name)) {
            Console_CommandLine::triggerError('option_bad_long_name', E_USER_ERROR, array('{$name}' => $this->name, '{$long_name}' => $this->long_name));
        }
        if (!is_string($this->action)) {
            Console_CommandLine::triggerError('option_bad_action', E_USER_ERROR, array('{$name}' => $this->name));
        }
        if (!isset(Console_CommandLine::$actions[$this->action])) {
            Console_CommandLine::triggerError('option_unregistered_action', E_USER_ERROR, array('{$action}' => $this->action, '{$name}' => $this->name));
        }
        if ($this->action == 'Callback' && !is_callable($this->callback)) {
            Console_CommandLine::triggerError('option_invalid_callback', E_USER_ERROR, array('{$name}' => $this->name));
        }
    }
    public function setDefaults() {
        if ($this->default !== null) {
            return;
        }
        switch ($this->action) {
            case 'Counter':
            case 'StoreInt':
                $this->default = 0;
            break;
            case 'StoreFloat':
                $this->default = 0.0;
            break;
            case 'StoreArray':
                $this->default = array();
            break;
            case 'StoreTrue':
                $this->default = false;
            break;
            case 'StoreFalse':
                $this->default = true;
            break;
            default:
                return;
        }
    }
    
}

abstract class Console_CommandLine_Element {
    public $name;
    public $help_name;
    public $description;
    public $messages = array();
    public function __construct($name = null, $params = array()) {
        $this->name = $name;
        foreach ($params as $attr => $value) {
            if (property_exists($this, $attr)) {
                $this->$attr = $value;
            }
        }
    }
    public function toString() {
        return $this->help_name;
    }
    public function validate() {
        if ($this->help_name == null) {
            $this->help_name = $this->name;
        }
    }
    
}
abstract class Console_CommandLine_Action {
    protected $result;
    protected $option;
    protected $parser;
    public function __construct($result, $option, $parser) {
        $this->result = $result;
        $this->option = $option;
        $this->parser = $parser;
    }
    public function getResult() {
        if (isset($this->result->options[$this->option->name])) {
            return $this->result->options[$this->option->name];
        }
        return null;
    }
    public function format(&$value) {
        return $value;
    }
    public function setResult($result) {
        $this->result->options[$this->option->name] = $result;
    }
    abstract public function execute($value = false, $params = array());
    
}

class Console_CommandLine_Argument extends Console_CommandLine_Element {
    public $multiple = false;
    public $optional = false;
    public function validate() {
        if (!preg_match('/^[a-zA-Z_\x7f-\xff]+[a-zA-Z0-9_\x7f-\xff]*$/', $this->name)) {
            Console_CommandLine::triggerError('argument_bad_name', E_USER_ERROR, array('{$name}' => $this->name));
        }
        parent::validate();
    }
    
}

class Console_CommandLine_MessageProvider_Default implements Console_CommandLine_MessageProvider, Console_CommandLine_CustomMessageProvider {
    protected $messages = array('OPTION_VALUE_REQUIRED' => 'Option "{$name}" requires a value.', 'OPTION_VALUE_UNEXPECTED' => 'Option "{$name}" does not expect a value (got "{$value}").', 'OPTION_VALUE_NOT_VALID' => 'Option "{$name}" must be one of the following: "{$choices}" (got "{$value}").', 'OPTION_VALUE_TYPE_ERROR' => 'Option "{$name}" requires a value of type {$type} (got "{$value}").', 'OPTION_AMBIGUOUS' => 'Ambiguous option "{$name}", can be one of the following: {$matches}.', 'OPTION_UNKNOWN' => 'Unknown option "{$name}".', 'ARGUMENT_REQUIRED' => 'You must provide at least {$argnum} argument{$plural}.', 'PROG_HELP_LINE' => 'Type "{$progname} --help" to get help.', 'PROG_VERSION_LINE' => '{$progname} version {$version}.', 'COMMAND_HELP_LINE' => 'Type "{$progname} <command> --help" to get help on specific command.', 'USAGE_WORD' => 'Usage', 'OPTION_WORD' => 'Options', 'ARGUMENT_WORD' => 'Arguments', 'COMMAND_WORD' => 'Commands', 'PASSWORD_PROMPT' => 'Password: ', 'PASSWORD_PROMPT_ECHO' => 'Password (warning: will echo): ', 'INVALID_CUSTOM_INSTANCE' => 'Instance does not implement the required interface', 'LIST_OPTION_MESSAGE' => 'lists valid choices for option {$name}', 'LIST_DISPLAYED_MESSAGE' => 'Valid choices are: ', 'INVALID_SUBCOMMAND' => 'Command "{$command}" is not valid.',);
    public function get($code, $vars = array()) {
        if (!isset($this->messages[$code])) {
            return 'UNKNOWN';
        }
        return $this->replaceTemplateVars($this->messages[$code], $vars);
    }
    public function getWithCustomMessages($code, $vars = array(), $messages = array()) {
        if (isset($messages[$code])) {
            $message = $messages[$code];
        } elseif (isset($this->messages[$code])) {
            $message = $this->messages[$code];
        } else {
            $message = 'UNKNOWN';
        }
        return $this->replaceTemplateVars($message, $vars);
    }
    protected function replaceTemplateVars($message, $vars = array()) {
        $tmpkeys = array_keys($vars);
        $keys = array();
        foreach ($tmpkeys as $key) {
            $keys[] = '{$' . $key . '}';
        }
        return str_replace($keys, array_values($vars), $message);
    }
    
}
interface Console_CommandLine_MessageProvider {
    public function get($code, $vars = array());
    
}
interface Console_CommandLine_Outputter {
    public function stdout($msg);
    public function stderr($msg);
    
}

class Console_CommandLine_Action_List extends Console_CommandLine_Action {
    public function execute($value = false, $params = array()) {
        $list = isset($params['list']) ? $params['list'] : array();
        $msg = isset($params['message']) ? $params['message'] : $this->parser->message_provider->get('LIST_DISPLAYED_MESSAGE');
        $del = isset($params['delimiter']) ? $params['delimiter'] : ', ';
        $post = isset($params['post']) ? $params['post'] : "\n";
        $this->parser->outputter->stdout($msg . implode($del, $list) . $post);
        exit(0);
    }
    
}

class Console_CommandLine_Action_Counter extends Console_CommandLine_Action {
    public function execute($value = false, $params = array()) {
        $result = $this->getResult();
        if ($result === null) {
            $result = 0;
        }
        $this->setResult(++$result);
    }
    
}

class Console_CommandLine_Action_Password extends Console_CommandLine_Action {
    public function execute($value = false, $params = array()) {
        $this->setResult(empty($value) ? $this->_promptPassword() : $value);
    }
    private function _promptPassword() {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            fwrite(STDOUT, $this->parser->message_provider->get('PASSWORD_PROMPT_ECHO'));
            @flock(STDIN, LOCK_EX);
            $passwd = fgets(STDIN);
            @flock(STDIN, LOCK_UN);
        } else {
            fwrite(STDOUT, $this->parser->message_provider->get('PASSWORD_PROMPT'));
            system('stty -echo');
            @flock(STDIN, LOCK_EX);
            $passwd = fgets(STDIN);
            @flock(STDIN, LOCK_UN);
            system('stty echo');
        }
        return trim($passwd);
    }
    
}

class Console_CommandLine_Action_Version extends Console_CommandLine_Action {
    public function execute($value = false, $params = array()) {
        return $this->parser->displayVersion();
    }
    
}

class Console_CommandLine_Action_StoreInt extends Console_CommandLine_Action {
    public function execute($value = false, $params = array()) {
        if (!is_numeric($value)) {
            throw Console_CommandLine_Exception::factory('OPTION_VALUE_TYPE_ERROR', array('name' => $this->option->name, 'type' => 'int', 'value' => $value), $this->parser);
        }
        $this->setResult((int)$value);
    }
    
}

class Console_CommandLine_Action_StoreString extends Console_CommandLine_Action {
    public function execute($value = false, $params = array()) {
        $this->setResult((string)$value);
    }
    
}

class Console_CommandLine_Action_Callback extends Console_CommandLine_Action {
    public function execute($value = false, $params = array()) {
        $this->setResult(call_user_func($this->option->callback, $value, $this->option, $this->result, $this->parser, $params));
    }
    
}

class Console_CommandLine_Action_StoreFalse extends Console_CommandLine_Action {
    public function execute($value = false, $params = array()) {
        $this->setResult(false);
    }
    
}

class Console_CommandLine_Action_StoreArray extends Console_CommandLine_Action {
    protected $firstPass = true;
    public function execute($value = false, $params = array()) {
        $result = $this->getResult();
        if (null === $result || $this->firstPass) {
            $result = array();
            $this->firstPass = false;
        }
        $result[] = $value;
        $this->setResult($result);
    }
    
}

class Console_CommandLine_Action_Help extends Console_CommandLine_Action {
    public function execute($value = false, $params = array()) {
        return $this->parser->displayUsage();
    }
    
}

class Console_CommandLine_Action_StoreTrue extends Console_CommandLine_Action {
    public function execute($value = false, $params = array()) {
        $this->setResult(true);
    }
    
}

class Console_CommandLine_Action_StoreFloat extends Console_CommandLine_Action {
    public function execute($value = false, $params = array()) {
        if (!is_numeric($value)) {
            throw Console_CommandLine_Exception::factory('OPTION_VALUE_TYPE_ERROR', array('name' => $this->option->name, 'type' => 'float', 'value' => $value), $this->parser);
        }
        $this->setResult((float)$value);
    }
    
}

class Console_CommandLine_Result {
    public $options = array();
    public $args = array();
    public $command_name = false;
    public $command = false;
    
}

class Console_CommandLine_Command extends Console_CommandLine {
    public $aliases = array();
    public function __construct($params = array()) {
        if (isset($params['aliases'])) {
            $this->aliases = $params['aliases'];
        }
        parent::__construct($params);
    }
    
}

class Console_CommandLine_XmlParser {
    public static function parse($xmlfile) {
        if (!is_readable($xmlfile)) {
            Console_CommandLine::triggerError('invalid_xml_file', E_USER_ERROR, array('{$file}' => $xmlfile));
        }
        $doc = new DomDocument();
        $doc->load($xmlfile);
        self::validate($doc);
        $nodes = $doc->getElementsByTagName('command');
        $root = $nodes->item(0);
        return self::_parseCommandNode($root, true);
    }
    public static function parseString($xmlstr) {
        $doc = new DomDocument();
        $doc->loadXml($xmlstr);
        self::validate($doc);
        $nodes = $doc->getElementsByTagName('command');
        $root = $nodes->item(0);
        return self::_parseCommandNode($root, true);
    }
    public static function validate($doc) {
        $rngschema = <<<__XML__
<?xml version="1.0" encoding="UTF-8"?>

<!-- 
  This is the RNG file for validating Console_CommandLine xml definitions.

  Author  : David JEAN LOUIS
  Licence : MIT License
  Version : CVS: $Id: xmlschema.rng 282427 2009-06-19 10:22:48Z izi $
-->

<grammar xmlns="http://relaxng.org/ns/structure/1.0" 
         datatypeLibrary="http://www.w3.org/2001/XMLSchema-datatypes">

  <!-- structure -->
  <start>
      <ref name="ref_command"/>
  </start>

  <!-- Command node -->
  <define name="ref_command_subcommand_common">
    <interleave>
      <optional>
        <element name="name">
          <text/>
        </element>
      </optional>
      <optional>
        <element name="description">
          <text/>
        </element>
      </optional>
      <optional>
        <element name="version">
          <text/>
        </element>
      </optional>
      <optional>
        <element name="add_help_option">
          <ref name="ref_bool_choices"/>
        </element>
      </optional>
      <optional>
        <element name="add_version_option">
          <ref name="ref_bool_choices"/>
        </element>
      </optional>
      <optional>
        <element name="force_posix">
          <ref name="ref_bool_choices"/>
        </element>
      </optional>
      <optional>
        <ref name="ref_messages_common"/>
      </optional>
      <zeroOrMore>
        <ref name="ref_option"/>
      </zeroOrMore>
      <zeroOrMore>
        <ref name="ref_argument"/>
      </zeroOrMore>
      <zeroOrMore>
        <ref name="ref_subcommand"/>
      </zeroOrMore>
    </interleave>
  </define>

  <!-- command element -->

  <define name="ref_command">
    <element name="command">
      <interleave>
        <ref name="ref_command_subcommand_common"/>
      </interleave>
    </element>
  </define>

  <!-- subcommand element -->

  <define name="ref_subcommand">
    <element name="command">
      <interleave>
        <ref name="ref_command_subcommand_common"/>
        <optional>
          <element name="aliases">
            <zeroOrMore>
              <element name="alias">
                <text/>
              </element>
            </zeroOrMore>
          </element>
        </optional>
      </interleave>
    </element>
  </define>

  <!-- custom messages common element -->

  <define name="ref_messages_common">
    <element name="messages">
      <oneOrMore>
        <element name="message">
          <attribute name="name">
            <data type="string"/>
          </attribute>
          <text/>
        </element>
      </oneOrMore>
    </element>
  </define>

  <!-- options and arguments common elements -->

  <define name="ref_option_argument_common">
    <interleave>
      <optional>
        <element name="description">
          <text/>
        </element>
      </optional>
      <optional>
        <element name="help_name">
          <text/>
        </element>
      </optional>
      <optional>
        <element name="default">
          <text/>
        </element>
      </optional>
      <optional>
        <ref name="ref_messages_common"/>
      </optional>
    </interleave>
  </define>

  <!-- Option node -->
  <define name="ref_option">
    <element name="option">
      <attribute name="name">
        <data type="string"/>
      </attribute>
      <interleave>
        <optional>
          <element name="short_name">
            <text/>
          </element>
        </optional>
        <optional>
          <element name="long_name">
            <text/>
          </element>
        </optional>
        <ref name="ref_option_argument_common"/>
        <optional>
          <element name="action">
            <text/>
          </element>
        </optional>
        <optional>
          <element name="choices">
            <zeroOrMore>
              <element name="choice">
                <text/>
              </element>
            </zeroOrMore>
          </element>
        </optional>
        <optional>
          <element name="add_list_option">
            <ref name="ref_bool_choices"/>
          </element>
        </optional>
      </interleave>
    </element>
  </define>

  <!-- Argument node -->
  <define name="ref_argument">
    <element name="argument">
      <attribute name="name">
        <data type="string"/>
      </attribute>
      <interleave>
        <ref name="ref_option_argument_common"/>
        <optional>
          <element name="multiple">
            <ref name="ref_bool_choices"/>
          </element>
        </optional>
        <optional>
          <element name="optional">
            <ref name="ref_bool_choices"/>
          </element>
        </optional>
      </interleave>
    </element>
  </define>

  <!-- boolean choices -->
  <define name="ref_bool_choices">
    <choice>
      <value>true</value>
      <value>1</value>
      <value>on</value>
      <value>yes</value>
      <value>false</value>
      <value>0</value>
      <value>off</value>
      <value>no</value>
    </choice>
  </define>

</grammar>
__XML__;
        return $doc->relaxNGValidateSource($rngschema);
    }
    private static function _parseCommandNode($node, $isRootNode = false) {
        if ($isRootNode) {
            $obj = new Console_CommandLine();
        } else {
            $obj = new Console_CommandLine_Command();
        }
        foreach ($node->childNodes as $cNode) {
            $cNodeName = $cNode->nodeName;
            switch ($cNodeName) {
                case 'name':
                case 'description':
                case 'version':
                    $obj->$cNodeName = trim($cNode->nodeValue);
                break;
                case 'add_help_option':
                case 'add_version_option':
                case 'force_posix':
                    $obj->$cNodeName = self::_bool(trim($cNode->nodeValue));
                break;
                case 'option':
                    $obj->addOption(self::_parseOptionNode($cNode));
                break;
                case 'argument':
                    $obj->addArgument(self::_parseArgumentNode($cNode));
                break;
                case 'command':
                    $obj->addCommand(self::_parseCommandNode($cNode));
                break;
                case 'aliases':
                    if (!$isRootNode) {
                        foreach ($cNode->childNodes as $subChildNode) {
                            if ($subChildNode->nodeName == 'alias') {
                                $obj->aliases[] = trim($subChildNode->nodeValue);
                            }
                        }
                    }
                break;
                case 'messages':
                    $obj->messages = self::_messages($cNode);
                break;
                default:
                break;
            }
        }
        return $obj;
    }
    private static function _parseOptionNode($node) {
        $obj = new Console_CommandLine_Option($node->getAttribute('name'));
        foreach ($node->childNodes as $cNode) {
            $cNodeName = $cNode->nodeName;
            switch ($cNodeName) {
                case 'choices':
                    foreach ($cNode->childNodes as $subChildNode) {
                        if ($subChildNode->nodeName == 'choice') {
                            $obj->choices[] = trim($subChildNode->nodeValue);
                        }
                    }
                break;
                case 'messages':
                    $obj->messages = self::_messages($cNode);
                break;
                default:
                    if (property_exists($obj, $cNodeName)) {
                        $obj->$cNodeName = trim($cNode->nodeValue);
                    }
                break;
            }
        }
        if ($obj->action == 'Password') {
            $obj->argument_optional = true;
        }
        return $obj;
    }
    private static function _parseArgumentNode($node) {
        $obj = new Console_CommandLine_Argument($node->getAttribute('name'));
        foreach ($node->childNodes as $cNode) {
            $cNodeName = $cNode->nodeName;
            switch ($cNodeName) {
                case 'description':
                case 'help_name':
                case 'default':
                    $obj->$cNodeName = trim($cNode->nodeValue);
                break;
                case 'multiple':
                    $obj->multiple = self::_bool(trim($cNode->nodeValue));
                break;
                case 'optional':
                    $obj->optional = self::_bool(trim($cNode->nodeValue));
                break;
                case 'messages':
                    $obj->messages = self::_messages($cNode);
                break;
                default:
                break;
            }
        }
        return $obj;
    }
    private static function _bool($str) {
        return in_array((string)$str, array('true', '1', 'on', 'yes'));
    }
    private static function _messages(DOMNode $node) {
        $messages = array();
        foreach ($node->childNodes as $cNode) {
            if ($cNode->nodeType == XML_ELEMENT_NODE) {
                $name = $cNode->getAttribute('name');
                $value = trim($cNode->nodeValue);
                $messages[$name] = $value;
            }
        }
        return $messages;
    }
    
}

class Console_CommandLine_Exception extends PEAR_Exception {
    const OPTION_VALUE_REQUIRED = 1;
    const OPTION_VALUE_UNEXPECTED = 2;
    const OPTION_VALUE_TYPE_ERROR = 3;
    const OPTION_UNKNOWN = 4;
    const ARGUMENT_REQUIRED = 5;
    const INVALID_SUBCOMMAND = 6;
    public static function factory($code, $params, $parser, array $messages = array()) {
        $provider = $parser->message_provider;
        if ($provider instanceof Console_CommandLine_CustomMessageProvider) {
            $msg = $provider->getWithCustomMessages($code, $params, $messages);
        } else {
            $msg = $provider->get($code, $params);
        }
        $const = 'Console_CommandLine_Exception::' . $code;
        $code = defined($const) ? constant($const) : 0;
        return new Console_CommandLine_Exception($msg, $code);
    }
    
}

class Console_CommandLine_Outputter_Default implements Console_CommandLine_Outputter {
    public function stdout($msg) {
        if (defined('STDOUT')) {
            fwrite(STDOUT, $msg);
        } else {
            echo $msg;
        }
    }
    public function stderr($msg) {
        if (defined('STDERR')) {
            fwrite(STDERR, $msg);
        } else {
            echo $msg;
        }
    }
    
}
// End Console Commandline

// create the parser
$parser = new Console_CommandLine(array(
    'description' => 'Extract contents of a phar archive to a given directory',
    'version'     => '@package_version@',
    'name'        => 'phar-extract',
));

$parser->addOption('public', array(
    'short_name'  => '-P',
    'long_name'   => '--public',
    'action'      => 'StoreString',
    'description' => "Public key file (PEM) to verify signature.\nIf not given, <pharfilename.phar>.pubkey will be used."
));

$parser->addOption('list', array(
    'short_name'  => '-l',
    'long_name'   => '--list',
    'action'      => 'StoreTrue',
    'description' => "Only list the files, don't extract them."
));


$parser->addArgument('phar', array(
    'action'      => 'StoreString',
    'description' => "Input Phar archive filename e.g. phar.phar",
));

$parser->addArgument('destination', array(
    'action'      => 'StoreString',
    'description' => "Destination directory",
    'optional'    => true
));


// run the parser
try {
    $result = $parser->parse();
    $options = $result->options;
    $args = $result->args;
    if ($options['list'] !== true && !isset($args['destination'])) {
        throw Console_CommandLine_Exception::factory(
            'ARGUMENT_REQUIRED',
            array('argnum' => 2, 'plural' => 's'),
            $parser,
            $parser->messages
        );
    }
} catch (Exception $exc) {
    $parser->displayError($exc->getMessage());
}


echo $parser->name . ' ' . $parser->version . PHP_EOL . PHP_EOL;

// validate parameters
if (substr($args['phar'], -5) !== '.phar') {
    $parser->displayError("Input Phar must have .phar extension, {$args['phar']} given.", 2);
}

if (!file_exists($args['phar']) || !is_readable($args['phar'])) {
    $parser->displayError("Phar in '{$args['phar']}' does not exist or is not readable.", 4);
}

if ($options['public']) {
    if (!file_exists($options['public']) || !is_readable($options['public'])) {
        $parser->displayError("Public key in '{$options['public']}' does not exist or is not readable.", 4);
    }
}

if (!$options['list']) {
    if (!is_dir($args['destination']) || !is_writable($args['destination'])) {
        $parser->displayError("Destination directory '{$args['destination']}' does not exist or is not writable.\n,", 5);
    }
}

if ($options['public']) {
    $pubkey = $args['phar'] . '.pubkey';
    echo "Copying public key to $pubkey\n";
    if (!@copy($options['public'], $pubkey)) {
        $parser->displayError("Error copying {$options['public']} to $pubkey.\n", 6);
    }
}

try {
    echo "Opening Phar archive: {$args['phar']}..." . PHP_EOL;
    $phar = new Phar($args['phar']);
    $files_count = count($phar);

    if ($options['list']) { //list files
        echo "Listing {$files_count} file(s):" . PHP_EOL;
        foreach (new RecursiveIteratorIterator($phar) as $file) {
            echo preg_replace('#(.*?\.phar)#', '', $file) . PHP_EOL;
        }

    } else { // extract
        if (!Phar::canWrite()) {
            throw new Exception("Phar writing support is disabled in this PHP installation, set phar.readonly=0 in php.ini!");
        }
        echo "Extracting {$files_count} file(s) to: {$args['destination']}..." . PHP_EOL;
        $phar->extractTo($args['destination'], null, true);
    }

    if ($options['public']) {
        unlink($pubkey);
    }

} catch (Exception $e) {
    if ($options['public']) {
        unlink($pubkey);
    }
    $parser->displayError($e->getMessage(), 7);
}


echo PHP_EOL . "All done, exiting." . PHP_EOL;