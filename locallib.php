<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Self-contained compatibility shim replacing the local/mr (Moodlerooms) framework.
 *
 * Provides native PHP/Moodle replacements for every mr_* class used by this
 * plugin, removing the external local/mr dependency entirely.
 *
 * @package block_intelligent_learning
 */

defined('MOODLE_INTERNAL') || die();

// =============================================================================
// mr_helper_abstract
// Base class that every helper class (connector, date, gradematrix, …) extends.
// =============================================================================
if (!class_exists('mr_helper_abstract')) {
    abstract class mr_helper_abstract {
        
    }
}

// =============================================================================
// mr_helper
// Lazy-loading helper factory / proxy.
//
//   $helper->connector          -> returns cached helper instance (property access)
//   $helper->gradeperiod()      -> calls direct() on the helper and returns result
//   $helper->date($str)         -> calls direct($str) on the helper
// =============================================================================
if (!class_exists('mr_helper')) {
    class mr_helper {
        /** @var string Plugin directory path, e.g. 'blocks/intelligent_learning' */
        protected $plugin;
        /** @var mr_helper_abstract[] Cached instances keyed by name */
        protected $instances = [];

        public function __construct($plugin) {
            $this->plugin = $plugin;
        }

        /**
         * Require the helper file and return (or create) a cached instance.
         * Tries both naming conventions used across this codebase:
         *   blocks_intelligent_learning_helper_{name}
         *   block_intelligent_learning_helper_{name}
         */
        protected function load($name) {

            if (isset($this->instances[$name])) {
                return $this->instances[$name];
            }

            global $CFG;

            $file = $CFG->dirroot . '/' . $this->plugin . '/helper/' . $name . '.php';

            if (file_exists($file)) {
                require_once($file);
            }

            $base = str_replace('/', '_', $this->plugin);

            foreach ([
                $base . '_helper_' . $name,
                preg_replace('/^blocks_/', 'block_', $base) . '_helper_' . $name
            ] as $class) {

                if (class_exists($class)) {
                    $this->instances[$name] = new $class();
                    return $this->instances[$name];
                }
            }

            throw new coding_exception(
                "Helper '{$name}' not found for plugin '{$this->plugin}'"
            );
        }

        /** Property access — returns helper instance without calling direct(). */
        public function __get($name) {
            return $this->load($name);
        }

        /** Method call — delegates to direct() on the helper instance. */
        public function __call($name, $args) {
            return call_user_func_array([$this->load($name), 'direct'], $args);
        }
    }
}

// =============================================================================
// mr_notify
// Queues user-facing notifications via Moodle's core notification API so
// they survive a redirect().
// =============================================================================
if (!class_exists('mr_notify')) {
    class mr_notify {
        /**
         * Error notification using a lang string key.
         * @param string $key     Lang string key in block_intelligent_learning
         * @param string $message Optional extra text appended after the string
         */
        public function bad($key, $message = '') {
            $str = get_string($key, 'block_intelligent_learning');
            if ($message !== '') {
                $str .= ' ' . $message;
            }
            \core\notification::add($str, \core\output\notification::NOTIFY_ERROR);
        }

        /**
         * Success notification using a lang string key.
         * @param string $key  Lang string key in block_intelligent_learning
         */
        public function good($key) {
            \core\notification::add(
                get_string($key, 'block_intelligent_learning'),
                \core\output\notification::NOTIFY_SUCCESS);
        }

        /**
         * Error notification using a literal string.
         * @param string $string Raw message text
         */
        public function add_string($string) {
            \core\notification::add((string) $string, \core\output\notification::NOTIFY_ERROR);
        }
    }
}

// =============================================================================
// mr_tabs
// Collects tab definitions and renders them as a Moodle tab tree.
// =============================================================================
if (!class_exists('mr_tabs')) {
    class mr_tabs {
        /** @var array Raw tab definitions */
        protected $tabs = [];

        /**
         * Register a tab.
         * @param string      $name    Identifier / lang string key
         * @param array       $params  URL parameters for this tab
         * @param string|null $parent  Parent tab name (for nested tabs)
         * @param int         $weight  Sort weight (lower = further left)
         */
        public function add($name, array $params, $parent = null, $weight = 0) {
            $this->tabs[] = compact('name', 'params', 'parent', 'weight');
        }

        /**
         * Render the tab row via $OUTPUT->tabtree().
         * @param int    $courseid   Included in every tab URL
         * @param string $activetab  Name of the currently selected tab
         * @return string HTML
         */
        public function render($courseid, $activetab = '') {
            global $OUTPUT;
            if (empty($this->tabs)) {
                return '';
            }
            usort($this->tabs, fn($a, $b) => $a['weight'] - $b['weight']);

            $tabobjects = [];
            foreach ($this->tabs as $t) {
                $p             = $t['params'];
                $p['courseid'] = $courseid;
                $url           = new moodle_url('/blocks/intelligent_learning/view.php', $p);
                $tabobjects[]  = new tabobject(
                    $t['name'], $url,
                    get_string($t['name'], 'block_intelligent_learning'));
            }
            return $OUTPUT->tabtree($tabobjects, $activetab);
        }
    }
}

// =============================================================================
// mr_controller_block
// Abstract base for block view controllers.
// Subclasses implement:
//   require_capability()  – public, capability check
//   init()                – protected, extra access / config checks
//   edit_action()         – returns HTML for the view
//   process_action()      – handles form POSTs (ends with redirect())
// =============================================================================
if (!class_exists('mr_controller_block')) {
    abstract class mr_controller_block {
        /** @var mr_helper */
        public $helper;
        /** @var object Plugin config (get_config result) */
        public $config;
        /** @var mr_notify */
        public $notify;
        /** @var moodle_url Base URL for this controller */
        public $url;

        /** @var string e.g. 'midtermgrades' */
        protected $controllername;
        /** @var context_course */
        protected $context;

        /** Expose $this->name as alias for $this->controllername. */
        public function __get($prop) {
            if ($prop === 'name') {
                return $this->controllername;
            }
            return null;
        }

        /**
         * Called by mr_controller::render() immediately after instantiation.
         */
        public function setup($controllername, $courseid) {
            $this->controllername = $controllername;
            $this->helper         = new mr_helper('blocks/intelligent_learning');
            $this->config         = get_config('blocks/intelligent_learning');
            $this->notify         = new mr_notify();
            $this->context        = context_course::instance($courseid);
            $this->url            = new moodle_url(
                '/blocks/intelligent_learning/view.php',
                ['controller' => $controllername, 'courseid' => $courseid]);
        }

        /** @return context_course */
        public function get_context() {
            return $this->context;
        }

        /** @return object */
        public function get_config() {
            return $this->config;
        }

        /** Override to enforce a capability check. */
        public function require_capability() {
            // Base no-op.
        }

        /** Override for additional access / business-logic validation. */
        protected function init() {
            // Base no-op.
        }

        /**
         * Dispatch entry point called by mr_controller::render().
         * Runs security + init checks then calls {$action}_action().
         *
         * @param  string $action e.g. 'edit' or 'process'
         * @return string|void  HTML from edit_action(), or void for process_action()
         */
        public function run($action) {
            $this->require_capability();
            $this->init();

            $method = $action . '_action';
            if (!method_exists($this, $method)) {
                throw new moodle_exception('invalidaction', 'block_intelligent_learning');
            }
            return $this->$method();
        }
    }
}

// =============================================================================
// mr_controller
// Static dispatcher called once from view.php.
// Reads controller / action / courseid from the request, sets up the Moodle
// page, collects navigation tabs, and renders the response.
// =============================================================================
if (!class_exists('mr_controller')) {
    class mr_controller {
        /**
         * @param string $plugin      Plugin path e.g. 'blocks/intelligent_learning'
         * @param string $langstring  Lang string key for the page title
         * @param string $component   Component for the lang string
         */
        public static function render($plugin, $langstring, $component) {
            global $CFG, $DB, $PAGE, $OUTPUT, $COURSE;

            $courseid  = required_param('courseid', PARAM_INT);
            $ctrlname  = optional_param('controller', 'midtermgrades', PARAM_ALPHANUMEXT);
            $action    = optional_param('action', 'edit', PARAM_ALPHANUMEXT);

            require_login($courseid);

            $course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $COURSE  = $course;
            $context = context_course::instance($courseid);

            $PAGE->set_url(new moodle_url('/blocks/intelligent_learning/view.php',
                ['controller' => $ctrlname, 'action' => $action, 'courseid' => $courseid]));
            $PAGE->set_context($context);
            $PAGE->set_pagelayout('incourse');
            $PAGE->set_title(get_string($langstring, $component));
            $PAGE->set_heading($course->fullname);

            // Instantiate the controller.
            $file = $CFG->dirroot . '/' . $plugin . '/controller/' . $ctrlname . '.php';
            if (!file_exists($file)) {
                throw new moodle_exception('invalidcontroller', $component);
            }
            require_once($file);

            $base  = str_replace('/', '_', $plugin);
            $class = preg_replace('/^blocks_/', 'block_', $base) . '_controller_' . $ctrlname;
            if (!class_exists($class)) {
                throw new moodle_exception('invalidcontrollerclass', $component);
            }

            /** @var mr_controller_block $ctrl */
            $ctrl = new $class();
            $ctrl->setup($ctrlname, $courseid);

            // POST actions: run, then redirect (process_action() must redirect itself).
            if ($action === 'process') {
                $ctrl->run('process');
                redirect(new moodle_url('/blocks/intelligent_learning/view.php',
                    ['controller' => $ctrlname, 'action' => 'edit', 'courseid' => $courseid]));
            }

            // Collect tabs from every known controller.
            $tabs     = new mr_tabs();
            $known    = ['midtermgrades', 'finalgrades', 'lastattendance', 'retentionalert'];
            foreach ($known as $tc) {
                $tcfile = $CFG->dirroot . '/' . $plugin . '/controller/' . $tc . '.php';
                if (file_exists($tcfile)) {
                    require_once($tcfile);
                    $tcclass = preg_replace('/^blocks_/', 'block_', $base) . '_controller_' . $tc;
                    if (class_exists($tcclass) && method_exists($tcclass, 'add_tabs')) {
                        $tcclass::add_tabs($ctrl, $tabs);
                    }
                }
            }

            $content = $ctrl->run($action);

            echo $OUTPUT->header();
            echo $tabs->render($courseid, $ctrlname);
            echo $content;
            echo $OUTPUT->footer();
        }
    }
}

// =============================================================================
// mr_server_abstract
// Minimal abstract base used only by unit tests (getMockForAbstractClass).
// =============================================================================
if (!class_exists('mr_server_abstract')) {
    abstract class mr_server_abstract {
        // Intentionally empty — exists solely for test mocking.
    }
}

// =============================================================================
// mr_server_response_abstract
// Abstract base for web-service response formatters.
// Concrete implementation: blocks_intelligent_learning_model_response
// =============================================================================
if (!class_exists('mr_server_response_abstract')) {
    abstract class mr_server_response_abstract {
        /** @var string Service method name, set by mr_server_rest before dispatch */
        public $servicemethod = '';

        abstract public function new_dom();
        abstract public function fault($message);
        abstract public function send_headers($server);
        abstract public function standard($response = null, $status = true);
        abstract public function post_handle($response);

        /**
         * Recursively converts a PHP array into DOM child nodes.
         */
        protected function array_to_dom(array $data, DOMDocument $dom, DOMElement $element) {
            foreach ($data as $key => $value) {
                if (is_numeric($key)) {
                    if (is_array($value)) {
                        $this->array_to_dom($value, $dom, $element);
                    } else {
                        $element->appendChild(
                            $dom->createTextNode((string)$value)
                        );
                    }
                    continue;
                }

                $child = $dom->createElement($key);
                $element->appendChild($child);
                if (is_array($value)) {
                    $this->array_to_dom($value, $dom, $child);
                } else {
                    $child->appendChild($dom->createTextNode((string) $value));
                }
            }
        }
    }
}

// =============================================================================
// mr_server_service_abstract
// Abstract base for web-service model classes.
// =============================================================================
if (!class_exists('mr_server_service_abstract')) {
    abstract class mr_server_service_abstract {
        /** @var mr_server_abstract */
        protected $server;
        /** @var mr_server_response_abstract */
        protected $response;
        /** @var mr_helper */
        protected $helper;

        public function __construct($server, $response) {
            $this->server   = $server;
            $this->response = $response;
            $this->init();
        }

        protected function init() {
            // Base no-op.
        }
    }
}

// =============================================================================
// Zend_Validate
// Minimal fluent validator chain replacing the Zend Framework class.
// =============================================================================
if (!class_exists('Zend_Validate')) {
    class Zend_Validate {
        /** @var object[] */
        protected $validators = [];

        /** @return $this */
        public function addValidator($validator) {
            $this->validators[] = $validator;
            return $this;
        }

        public function isValid() {
            foreach ($this->validators as $v) {
                if (!$v->isValid()) {
                    return false;
                }
            }
            return true;
        }
    }
}

// =============================================================================
// mr_server_validate_token
// Validates the shared-secret token from HTTP header (X-Token) or request param.
// =============================================================================
if (!class_exists('mr_server_validate_token')) {
    class mr_server_validate_token {
        protected $token;

        public function __construct($token) {
            $this->token = (string) $token;
        }

        public function isValid() {
            if ($this->token === '') {
                return false;
            }
            $supplied = '';
            if (!empty($_SERVER['HTTP_X_TOKEN'])) {
                $supplied = $_SERVER['HTTP_X_TOKEN'];
            } elseif (!empty($_SERVER['HTTP_TOKEN'])) {
                $supplied = $_SERVER['HTTP_TOKEN'];
            } elseif (isset($_REQUEST['token'])) {
                $supplied = $_REQUEST['token'];
            }
            return hash_equals($this->token, $supplied);
        }
    }
}

// =============================================================================
// mr_server_validate_method
// Validates that the HTTP request method is POST or GET.
// =============================================================================
if (!class_exists('mr_server_validate_method')) {
    class mr_server_validate_method {
        public function isValid() {
            return in_array($_SERVER['REQUEST_METHOD'] ?? '', ['POST', 'GET'], true);
        }
    }
}

// =============================================================================
// mr_server_validate_ip
// Validates the client IP against a comma-separated whitelist of IPs/subnets.
// An empty whitelist allows all IPs.
// =============================================================================
if (!class_exists('mr_server_validate_ip')) {
    class mr_server_validate_ip {
        protected $iplist;

        public function __construct($iplist) {
            $this->iplist = (string) $iplist;
        }

        public function isValid() {
            $list = trim($this->iplist);
            if ($list === '') {
                return true;
            }
            $remote = $_SERVER['REMOTE_ADDR'] ?? '';
            if ($remote === '') {
                return false;
            }
            foreach (array_map('trim', explode(',', $list)) as $subnet) {
                if ($subnet !== '' && address_in_subnet($remote, $subnet)) {
                    return true;
                }
            }
            return false;
        }
    }
}

// =============================================================================
// mr_server_validate_test
// Always-pass validator used by unit tests to bypass real checks.
// =============================================================================
if (!class_exists('mr_server_validate_test')) {
    class mr_server_validate_test {
        public function isValid() {
            return true;
        }
    }
}

// =============================================================================
// mr_server_rest
// Lightweight REST dispatcher replacing the Zend REST server.
//
// Request: POST or GET with  method=<serviceMethodName>&param1=val1…
// Steps: validate → reflect service method → map params → call → echo XML.
// =============================================================================
if (!class_exists('mr_server_rest')) {
    class mr_server_rest {
        protected $serviceclass;
        protected $responseclass;
        protected $validator;

        public function __construct($serviceclass, $responseclass, $validator) {
            $this->serviceclass  = $serviceclass;
            $this->responseclass = $responseclass;
            $this->validator     = $validator;
        }

        public function handle() {
            /** @var mr_server_response_abstract $responseobj */
            $responseobj = new $this->responseclass();

            try {
                if (!$this->validator->isValid()) {
                    throw new Exception('Request validation failed: invalid token, method, or IP address');
                }

                $method = isset($_REQUEST['method'])
                    ? clean_param($_REQUEST['method'], PARAM_ALPHANUMEXT) : '';
                if ($method === '') {
                    throw new Exception('No method parameter provided');
                }
                if (!method_exists($this->serviceclass, $method)) {
                    throw new Exception("Unknown service method: {$method}");
                }

                $reflection = new ReflectionMethod($this->serviceclass, $method);
                if (!$reflection->isPublic()) {
                    throw new Exception("Method not publicly accessible: {$method}");
                }

                $responseobj->servicemethod = $method;
                $service  = new $this->serviceclass($this, $responseobj);
                $callargs = [];

                foreach ($reflection->getParameters() as $param) {
                    $pname = $param->getName();
                    if (isset($_REQUEST[$pname])) {
                        $callargs[] = $_REQUEST[$pname];
                    } elseif ($param->isOptional()) {
                        $callargs[] = $param->getDefaultValue();
                    } else {
                        throw new Exception("Required parameter '{$pname}' missing from request");
                    }
                }

                $result = call_user_func_array([$service, $method], $callargs);
                $dom    = ($result instanceof DOMDocument)
                    ? $result : $responseobj->standard($result, true);

            } catch (Exception $e) {
                $dom = $responseobj->fault($e->getMessage());
            }

            $responseobj->send_headers($this);
            $xml = $dom->saveXML();
            echo $responseobj->post_handle($xml);
        }
    }
}

// =============================================================================
// mr_db_record
// Wraps a single database row and tracks whether any fields have changed.
// Used by model/gradematrix.php to batch-save grade records.
//
// Usage:
//   $rec = new mr_db_record('block_intelligent_learning', $existingRow);
//   $rec->set($updatedRow);
//   if ($rec->is_changed()) { ... }
//   echo $rec->finalgrade;  // proxies to current data
// =============================================================================
if (!class_exists('mr_db_record')) {
    class mr_db_record {
        /** @var string Moodle table name (without prefix) */
        protected $table;
        /** @var object Original row from the database */
        protected $original;
        /** @var object Current (possibly modified) row */
        protected $current;

        public function __construct($table, $record) {
            $this->table    = $table;
            $this->original = is_object($record) ? clone $record : (object) (array) $record;
            $this->current  = is_object($record) ? clone $record : (object) (array) $record;
        }

        /**
         * Merge properties from $newrecord into the current row.
         * Only non-null values overwrite existing ones.
         */
        public function set($newrecord) {
            if (is_object($newrecord)) {
                foreach ((array) $newrecord as $k => $v) {
                    $this->current->$k = $v;
                }
            }
        }

        /**
         * Returns true if any field differs from the original database row.
         */
        public function is_changed() {
            foreach ((array) $this->current as $k => $v) {
                $orig = isset($this->original->$k) ? $this->original->$k : null;
                if ($orig != $v) {
                    return true;
                }
            }
            return false;
        }

        /** @return object The current (possibly modified) record */
        public function get_record() {
            return $this->current;
        }

        /** @return string Table name */
        public function get_table() {
            return $this->table;
        }

        /** Proxy property reads to the current record data. */
        public function __get($name) {
            return isset($this->current->$name) ? $this->current->$name : null;
        }

        /** Proxy property writes to the current record data. */
        public function __set($name, $value) {
            $this->current->$name = $value;
        }

        /** Proxy isset checks. */
        public function __isset($name) {
            return isset($this->current->$name);
        }
    }
}

// =============================================================================
// mr_db_queue
// Collects mr_db_record objects and flushes changed ones to the database.
//
// Usage:
//   $queue = new mr_db_queue();
//   $queue->add($recordsArray);   // array of mr_db_record
//   $queue->flush();              // writes changed records to DB
// =============================================================================
if (!class_exists('mr_db_queue')) {
    class mr_db_queue {
        /** @var mr_db_record[] */
        protected $records = [];

        /**
         * Add one or more mr_db_record objects.
         * Accepts a single mr_db_record or an array of them.
         */
        public function add($records) {
            if ($records instanceof mr_db_record) {
                $this->records[] = $records;
            } elseif (is_array($records)) {
                foreach ($records as $r) {
                    if ($r instanceof mr_db_record) {
                        $this->records[] = $r;
                    }
                }
            }
        }

        /**
         * Write all changed records to the database, then clear the queue.
         */
        public function flush() {
            global $DB;
            foreach ($this->records as $rec) {
                if (!$rec->is_changed()) {
                    continue;
                }
                $data = $rec->get_record();
                if (!empty($data->id)) {
                    $DB->update_record($rec->get_table(), $data);
                } else {
                    $newid = $DB->insert_record($rec->get_table(), $data);
                    $data->id = $newid;
                }
            }
            $this->records = [];
        }
    }
}
