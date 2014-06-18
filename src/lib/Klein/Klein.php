<?php
/**
 * Klein (klein.php) - A lightning fast router for PHP
 *
 * @author      Chris O'Hara <cohara87@gmail.com>
 * @author      Trevor Suarez (Rican7) (contributor and v2 refactorer)
 * @copyright   (c) Chris O'Hara
 * @link        https://github.com/chriso/klein.php
 * @license     MIT
 */

namespace Klein;

use \Exception;
use \OutOfBoundsException;

use \Klein\DataCollection\RouteCollection;
use \Klein\Exceptions\LockedResponseException;
use \Klein\Exceptions\UnhandledException;
use \Klein\Exceptions\DispatchHaltedException;
use \Klein\Exceptions\HttpException;
use \Klein\Exceptions\HttpExceptionInterface;

/**
 * Klein
 *
 * Main Klein router class
 * 
 * @package     Klein
 */
class Klein
{

    /**
     * Class constants
     */

    /**
     * The regular expression used to compile and match URL's
     *
     * @const string
     */
    const ROUTE_COMPILE_REGEX = '`(\\\?(?:/|\.|))(\[([^:\]]*+)(?::([^:\]]*+))?\])(\?|)`';

    /**
     * The regular expression used to escape the non-named param section of a route URL
     *
     * @const string
     */
    const ROUTE_ESCAPE_REGEX = '`(?<=^|\])[^\]\[\?]+?(?=\[|$)`';

    /**
     * Dispatch route output handling
     *
     * Don't capture anything. Behave as normal.
     *
     * @const int
     */
    const DISPATCH_NO_CAPTURE = 0;

    /**
     * Dispatch route output handling
     *
     * Capture all output and return it from dispatch
     *
     * @const int
     */
    const DISPATCH_CAPTURE_AND_RETURN = 1;

    /**
     * Dispatch route output handling
     *
     * Capture all output and replace the response body with it
     *
     * @const int
     */
    const DISPATCH_CAPTURE_AND_REPLACE = 2;

    /**
     * Dispatch route output handling
     *
     * Capture all output and prepend it to the response body
     *
     * @const int
     */
    const DISPATCH_CAPTURE_AND_PREPEND = 3;

    /**
     * Dispatch route output handling
     *
     * Capture all output and append it to the response body
     *
     * @const int
     */
    const DISPATCH_CAPTURE_AND_APPEND = 4;


    /**
     * Class properties
     */

    /**
     * Collection of the routes to match on dispatch
     *
     * @var RouteCollection
     * @access protected
     */
    protected $routes;

    /**
     * The Route factory object responsible for creating Route instances
     *
     * @var AbstractRouteFactory
     * @access protected
     */
    protected $route_factory;

    /**
     * An array of error callback callables
     *
     * @var array[callable]
     * @access protected
     */
    protected $errorCallbacks = array();

    /**
     * An array of HTTP error callback callables
     *
     * @var array[callable]
     * @access protected
     */
    protected $httpErrorCallbacks = array();

    /**
     * An array of callbacks to call after processing the dispatch loop
     * and before the response is sent
     *
     * @var array[callable]
     * @access protected
     */
    protected $afterFilterCallbacks = array();


    /**
     * Route objects
     */

    /**
     * The Request object passed to each matched route
     *
     * @var Request
     * @access protected
     */
    protected $request;

    /**
     * The Response object passed to each matched route
     *
     * @var Response
     * @access protected
     */
    protected $response;

    /**
     * The service provider object passed to each matched route
     *
     * @var ServiceProvider
     * @access protected
     */
    protected $service;

    /**
     * A generic variable passed to each matched route
     *
     * @var mixed
     * @access protected
     */
    protected $app;


    /**
     * Methods
     */

    /**
     * Constructor
     *
     * Create a new Klein instance with optionally injected dependencies
     * This DI allows for easy testing, object mocking, or class extension
     *
     * @param ServiceProvider $service              Service provider object responsible for utilitarian behaviors
     * @param mixed $app                            An object passed to each route callback, defaults to an App instance
     * @param RouteCollection $routes               Collection object responsible for containing all route instances
     * @param AbstractRouteFactory $route_factory   A factory class responsible for creating Route instances
     * @access public
     */
    public function __construct(
        ServiceProvider $service = null,
        $app = null,
        RouteCollection $routes = null,
        AbstractRouteFactory $route_factory = null
    ) {
        // Instanciate and fall back to defaults
        $this->service       = $service       ?: new ServiceProvider();
        $this->app           = $app           ?: new App();
        $this->routes        = $routes        ?: new RouteCollection();
        $this->route_factory = $route_factory ?: new RouteFactory();
    }

    /**
     * Returns the routes object
     *
     * @access public
     * @return RouteCollection
     */
    public function routes()
    {
        return $this->routes;
    }

    /**
     * Returns the request object
     *
     * @access public
     * @return Request
     */
    public function request()
    {
        return $this->request;
    }

    /**
     * Returns the response object
     *
     * @access public
     * @return Response
     */
    public function response()
    {
        return $this->response;
    }

    /**
     * Returns the service object
     *
     * @access public
     * @return ServiceProvider
     */
    public function service()
    {
        return $this->service;
    }

    /**
     * Returns the app object
     *
     * @access public
     * @return mixed
     */
    public function app()
    {
        return $this->app;
    }

    /**
     * Parse our extremely loose argument order of our "respond" method and its aliases
     *
     * This method takes its arguments in a loose format and order.
     * The method signature is simply there for documentation purposes, but allows
     * for the minimum of a callback to be passed in its current configuration.
     *
     * @see Klein::respond()
     * @param mixed $args               An argument array. Hint: This works well when passing "func_get_args()"
     *  @named string | array $method   HTTP Method to match
     *  @named string $path             Route URI path to match
     *  @named callable $callback       Callable callback method to execute on route match
     * @access protected
     * @return array                    A named parameter array containing the keys: 'method', 'path', and 'callback'
     */
    protected function parseLooseArgumentOrder(array $args)
    {
        // Get the arguments in a very loose format
        $callback = array_pop($args);
        $path = array_pop($args);
        $method = array_pop($args);

        // Return a named parameter array
        return array(
            'method' => $method,
            'path' => $path,
            'callback' => $callback,
        );
    }

    /**
     * Add a new route to be matched on dispatch
     *
     * Essentially, this method is a standard "Route" builder/factory,
     * allowing a loose argument format and a standard way of creating
     * Route instances
     *
     * This method takes its arguments in a very loose format
     * The only "required" parameter is the callback (which is very strange considering the argument definition order)
     *
     * <code>
     * $router = new Klein();
     *
     * $router->respond( function() {
     *     echo 'this works';
     * });
     * $router->respond( '/endpoint', function() {
     *     echo 'this also works';
     * });
     * $router->respond( 'POST', '/endpoint', function() {
     *     echo 'this also works!!!!';
     * });
     * </code>
     *
     * @param string | array $method    HTTP Method to match
     * @param string $path              Route URI path to match
     * @param callable $callback        Callable callback method to execute on route match
     * @access public
     * @return Route
     */
    public function respond($method, $path = '*', $callback = null)
    {
        // Get the arguments in a very loose format
        extract(
            $this->parseLooseArgumentOrder(func_get_args()),
            EXTR_OVERWRITE
        );

        $route = $this->route_factory->build($callback, $path, $method);

        $this->routes->add($route);

        return $route;
    }

    /**
     * Collect a set of routes under a common namespace
     *
     * The routes may be passed in as either a callable (which holds the route definitions),
     * or as a string of a filename, of which to "include" under the Klein router scope
     *
     * <code>
     * $router = new Klein();
     *
     * $router->with('/users', function($router) {
     *     $router->respond( '/', function() {
     *         // do something interesting
     *     });
     *     $router->respond( '/[i:id]', function() {
     *         // do something different
     *     });
     * });
     *
     * $router->with('/cars', __DIR__ . '/routes/cars.php');
     * </code>
     *
     * @param string $namespace                     The namespace under which to collect the routes
     * @param callable | string[filename] $routes   The defined routes to collect under the namespace
     * @access public
     * @return void
     */
    public function with($namespace, $routes)
    {
        $previous = $this->route_factory->getNamespace();

        $this->route_factory->appendNamespace($namespace);

        if (is_callable($routes)) {
            if (is_string($routes)) {
                $routes($this);
            } else {
                call_user_func($routes, $this);
            }
        } else {
            require $routes;
        }

        $this->route_factory->setNamespace($previous);
    }

    /**
     * Dispatch the request to the approriate route(s)
     *
     * Dispatch with optionally injected dependencies
     * This DI allows for easy testing, object mocking, or class extension
     *
     * @param Request $request              The request object to give to each callback
     * @param AbstractResponse $response    The response object to give to each callback
     * @param boolean $send_response        Whether or not to "send" the response after the last route has been matched
     * @param int $capture                  Specify a DISPATCH_* constant to change the output capturing behavior
     * @access public
     * @return void|string
     */
    public function dispatch(
        Request $request = null,
        AbstractResponse $response = null,
        $send_response = true,
        $capture = self::DISPATCH_NO_CAPTURE
    ) {
        // Set/Initialize our objects to be sent in each callback
        $this->request = $request ?: Request::createFromGlobals();
        $this->response = $response ?: new Response();

        // Bind our objects to our service
        $this->service->bind($this->request, $this->response);

        // Prepare any named routes
        $this->routes->prepareNamed();


        // Grab some data from the request
        $uri = $this->request->pathname();
        $req_method = $this->request->method();

        // Set up some variables for matching
        $skip_num = 0;
        $matched = $this->routes->cloneEmpty(); // Get a clone of the routes collection, as it may have been injected
        $methods_matched = array();
        $params = array();
        $apc = function_exists('apc_fetch');

        ob_start();

        foreach ($this->routes as $route) {
            // Are we skipping any matches?
            if ($skip_num > 0) {
                $skip_num--;
                continue;
            }

            // Grab the properties of the route handler
            $method = $route->getMethod();
            $path = $route->getPath();
            $count_match = $route->getCountMatch();

            // Keep track of whether this specific request method was matched
            $method_match = null;

            // Was a method specified? If so, check it against the current request method
            if (is_array($method)) {
                foreach ($method as $test) {
                    if (strcasecmp($req_method, $test) === 0) {
                        $method_match = true;
                    } elseif (strcasecmp($req_method, 'HEAD') === 0
                          && (strcasecmp($test, 'HEAD') === 0 || strcasecmp($test, 'GET') === 0)) {

                        // Test for HEAD request (like GET)
                        $method_match = true;
                    }
                }

                if (null === $method_match) {
                    $method_match = false;
                }
            } elseif (null !== $method && strcasecmp($req_method, $method) !== 0) {
                $method_match = false;

                // Test for HEAD request (like GET)
                if (strcasecmp($req_method, 'HEAD') === 0
                    && (strcasecmp($method, 'HEAD') === 0 || strcasecmp($method, 'GET') === 0 )) {

                    $method_match = true;
                }
            } elseif (null !== $method && strcasecmp($req_method, $method) === 0) {
                $method_match = true;
            }

            // If the method was matched or if it wasn't even passed (in the route callback)
            $possible_match = (null === $method_match) || $method_match;

            // ! is used to negate a match
            if (isset($path[0]) && $path[0] === '!') {
                $negate = true;
                $i = 1;
            } else {
                $negate = false;
                $i = 0;
            }

            // Check for a wildcard (match all)
            if ($path === '*') {
                $match = true;

            } elseif (($path === '404' && $matched->isEmpty() && count($methods_matched) <= 0)
                   || ($path === '405' && $matched->isEmpty() && count($methods_matched) > 0)) {

                // Easily handle 40x's
                // TODO: Possibly remove in future, here for backwards compatibility
                $this->onHttpError($route);

                continue;

            } elseif (isset($path[$i]) && $path[$i] === '@') {
                // @ is used to specify custom regex

                $match = preg_match('`' . substr($path, $i + 1) . '`', $uri, $params);

            } else {
                // Compiling and matching regular expressions is relatively
                // expensive, so try and match by a substring first

                $expression = null;
                $regex = false;
                $j = 0;
                $n = isset($path[$i]) ? $path[$i] : null;

                // Find the longest non-regex substring and match it against the URI
                while (true) {
                    if (!isset($path[$i])) {
                        break;
                    } elseif (false === $regex) {
                        $c = $n;
                        $regex = $c === '[' || $c === '(' || $c === '.';
                        if (false === $regex && false !== isset($path[$i+1])) {
                            $n = $path[$i + 1];
                            $regex = $n === '?' || $n === '+' || $n === '*' || $n === '{';
                        }
                        if (false === $regex && $c !== '/' && (!isset($uri[$j]) || $c !== $uri[$j])) {
                            continue 2;
                        }
                        $j++;
                    }
                    $expression .= $path[$i++];
                }

                // Check if there's a cached regex string
                if (false !== $apc) {
                    $regex = apc_fetch("route:$expression");
                    if (false === $regex) {
                        $regex = $this->compileRoute($expression);
                        apc_store("route:$expression", $regex);
                    }
                } else {
                    $regex = $this->compileRoute($expression);
                }

                $match = preg_match($regex, $uri, $params);
            }

            if (isset($match) && $match ^ $negate) {
                if ($possible_match) {
                    if (!empty($params)) {
                        /**
                         * URL Decode the params according to RFC 3986
                         * @link http://www.faqs.org/rfcs/rfc3986
                         *
                         * Decode here AFTER matching as per @chriso's suggestion
                         * @link https://github.com/chriso/klein.php/issues/117#issuecomment-21093915
                         */
                        $params = array_map('rawurldecode', $params);

                        $this->request->paramsNamed()->merge($params);
                    }

                    // Handle our response callback
                    try {
                        $this->handleRouteCallback($route, $matched, $methods_matched);

                    } catch (DispatchHaltedException $e) {
                        switch ($e->getCode()) {
                            case DispatchHaltedException::SKIP_THIS:
                                continue 2;
                                break;
                            case DispatchHaltedException::SKIP_NEXT:
                                $skip_num = $e->getNumberOfSkips();
                                break;
                            case DispatchHaltedException::SKIP_REMAINING:
                                break 2;
                            default:
                                throw $e;
                        }
                    }

                    if ($path !== '*') {
                        $count_match && $matched->add($route);
                    }
                }

                // Keep track of possibly matched methods
                $methods_matched = array_merge($methods_matched, (array) $method);
                $methods_matched = array_filter($methods_matched);
                $methods_matched = array_unique($methods_matched);
            }
        }

        // Handle our 404/405 conditions
        try {
            if ($matched->isEmpty() && count($methods_matched) > 0) {
                // Add our methods to our allow header
                $this->response->header('Allow', implode(', ', $methods_matched));

                if (strcasecmp($req_method, 'OPTIONS') !== 0) {
                    throw HttpException::createFromCode(405);
                }
            } elseif ($matched->isEmpty()) {
                throw HttpException::createFromCode(404);
            }
        } catch (HttpExceptionInterface $e) {
            // Grab our original response lock state
            $locked = $this->response->isLocked();

            // Call our http error handlers
            $this->httpError($e, $matched, $methods_matched);

            // Make sure we return our response to its original lock state
            if (!$locked) {
                $this->response->unlock();
            }
        }

        try {
            if ($this->response->chunked) {
                $this->response->chunk();

            } else {
                // Output capturing behavior
                switch($capture) {
                    case self::DISPATCH_CAPTURE_AND_RETURN:
                        $buffed_content = null;
                        if (ob_get_level()) {
                            $buffed_content = ob_get_clean();
                        }
                        return $buffed_content;
                        break;
                    case self::DISPATCH_CAPTURE_AND_REPLACE:
                        if (ob_get_level()) {
                            $this->response->body(ob_get_clean());
                        }
                        break;
                    case self::DISPATCH_CAPTURE_AND_PREPEND:
                        if (ob_get_level()) {
                            $this->response->prepend(ob_get_clean());
                        }
                        break;
                    case self::DISPATCH_CAPTURE_AND_APPEND:
                        if (ob_get_level()) {
                            $this->response->append(ob_get_clean());
                        }
                        break;
                    case self::DISPATCH_NO_CAPTURE:
                    default:
                        if (ob_get_level()) {
                            ob_end_flush();
                        }
                }
            }

            // Test for HEAD request (like GET)
            if (strcasecmp($req_method, 'HEAD') === 0) {
                // HEAD requests shouldn't return a body
                $this->response->body('');

                if (ob_get_level()) {
                    ob_clean();
                }
            }
        } catch (LockedResponseException $e) {
            // Do nothing, since this is an automated behavior
        }

        // Run our after dispatch callbacks
        $this->callAfterDispatchCallbacks();

        if ($send_response && !$this->response->isSent()) {
            $this->response->send();
        }
    }

    /**
     * Compiles a route string to a regular expression
     *
     * @param string $route     The route string to compile
     * @access protected
     * @return void
     */
    protected function compileRoute($route)
    {
        // First escape all of the non-named param (non [block]s) for regex-chars
        if (preg_match_all(static::ROUTE_ESCAPE_REGEX, $route, $escape_locations, PREG_SET_ORDER)) {
            foreach ($escape_locations as $locations) {
                $route = str_replace($locations[0], preg_quote($locations[0]), $route);
            }
        }

        // Now let's actually compile the path
        if (preg_match_all(static::ROUTE_COMPILE_REGEX, $route, $matches, PREG_SET_ORDER)) {
            $match_types = array(
                'i'  => '[0-9]++',
                'a'  => '[0-9A-Za-z]++',
                'h'  => '[0-9A-Fa-f]++',
                's'  => '[0-9A-Za-z-_]++',
                '*'  => '.+?',
                '**' => '.++',
                ''   => '[^/]+?'
            );

            foreach ($matches as $match) {
                list($block, $pre, $inner_block, $type, $param, $optional) = $match;

                if (isset($match_types[$type])) {
                    $type = $match_types[$type];
                }
                // Older versions of PCRE require the 'P' in (?P<named>)
                $pattern = '(?:'
                         . ($pre !== '' ? $pre : null)
                         . '('
                         . ($param !== '' ? "?P<$param>" : null)
                         . $type
                         . '))'
                         . ($optional !== '' ? '?' : null);

                $route = str_replace($block, $pattern, $route);
            }
        }

        return "`^$route$`";
    }

    /**
     * Get the path for a given route
     *
     * This looks up the route by its passed name and returns
     * the path/url for that route, with its URL params as
     * placeholders unless you pass a valid key-value pair array
     * of the placeholder params and their values
     *
     * If a pathname is a complex/custom regular expression, this
     * method will simply return the regular expression used to
     * match the request pathname, unless an optional boolean is
     * passed "flatten_regex" which will flatten the regular
     * expression into a simple path string
     *
     * This method, and its style of reverse-compilation, was originally
     * inspired by a similar effort by Gilles Bouthenot (@gbouthenot)
     *
     * @link https://github.com/gbouthenot
     * @param string $route_name        The name of the route
     * @param array $params             The array of placeholder fillers
     * @param boolean $flatten_regex    Optionally flatten custom regular expressions to "/"
     * @throws OutOfBoundsException     If the route requested doesn't exist
     * @access public
     * @return string
     */
    public function getPathFor($route_name, array $params = null, $flatten_regex = true)
    {
        // First, grab the route
        $route = $this->routes->get($route_name);

        // Make sure we are getting a valid route
        if (null === $route) {
            throw new OutOfBoundsException('No such route with name: '. $route_name);
        }

        $path = $route->getPath();

        if (preg_match_all(static::ROUTE_COMPILE_REGEX, $path, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                list($block, $pre, $inner_block, $type, $param, $optional) = $match;

                if (isset($params[$param])) {
                    $path = str_replace($block, $pre. $params[$param], $path);
                } elseif ($optional) {
                    $path = str_replace($block, '', $path);
                }
            }

        } elseif ($flatten_regex && strpos($path, '@') === 0) {
            // If the path is a custom regular expression and we're "flattening", just return a slash
            $path = '/';
        }

        return $path;
    }

    /**
     * Handle a route's callback
     *
     * This handles common exceptions and their output
     * to keep the "dispatch()" method DRY
     *
     * @param Route $route
     * @param RouteCollection $matched
     * @param int $methods_matched
     * @access protected
     * @return void
     */
    protected function handleRouteCallback(Route $route, RouteCollection $matched, $methods_matched)
    {
        // Handle the callback
        try {
            $returned = call_user_func(
                $route->getCallback(), // Instead of relying on the slower "invoke" magic
                $this->request,
                $this->response,
                $this->service,
                $this->app,
                $this, // Pass the Klein instance
                $matched,
                $methods_matched
            );

            if ($returned instanceof AbstractResponse) {
                $this->response = $returned;
            } else {
                // Otherwise, attempt to append the returned data
                try {
                    $this->response->append($returned);
                } catch (LockedResponseException $e) {
                    // Do nothing, since this is an automated behavior
                }
            }
        } catch (DispatchHaltedException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            // Call our http error handlers
            $this->httpError($e, $matched, $methods_matched);

            throw new DispatchHaltedException();
        } catch (Exception $e) {
            $this->error($e);
        }
    }

    /**
     * Adds an error callback to the stack of error handlers
     *
     * @param callable $callback            The callable function to execute in the error handling chain
     * @access public
     * @return boolean|void
     */
    public function onError($callback)
    {
        $this->errorCallbacks[] = $callback;
    }

    /**
     * Routes an exception through the error callbacks
     *
     * @param Exception $err        The exception that occurred
     * @throws UnhandledException   If the error/exception isn't handled by an error callback
     * @access protected
     * @return void
     */
    protected function error(Exception $err)
    {
        $type = get_class($err);
        $msg = $err->getMessage();

        if (count($this->errorCallbacks) > 0) {
            foreach (array_reverse($this->errorCallbacks) as $callback) {
                if (is_callable($callback)) {
                    if (is_string($callback)) {
                        $callback($this, $msg, $type, $err);

                        return;
                    } else {
                        call_user_func($callback, $this, $msg, $type, $err);

                        return;
                    }
                } else {
                    if (null !== $this->service && null !== $this->response) {
                        $this->service->flash($err);
                        $this->response->redirect($callback);
                    }
                }
            }
        } else {
            $this->response->code(500);
            throw new UnhandledException($err);
        }

        // Lock our response, since we probably don't want
        // anything else messing with our error code/body
        $this->response->lock();
    }

    /**
     * Adds an HTTP error callback to the stack of HTTP error handlers
     *
     * @param callable $callback            The callable function to execute in the error handling chain
     * @access public
     * @return void
     */
    public function onHttpError($callback)
    {
        $this->httpErrorCallbacks[] = $callback;
    }

    /**
     * Handles an HTTP error exception through our HTTP error callbacks
     *
     * @param HttpExceptionInterface $http_exception    The exception that occurred
     * @param RouteCollection $matched                  The collection of routes that were matched in dispatch
     * @param array $methods_matched                    The HTTP methods that were matched in dispatch
     * @access protected
     * @return void
     */
    protected function httpError(HttpExceptionInterface $http_exception, RouteCollection $matched, $methods_matched)
    {
        if (!$this->response->isLocked()) {
            $this->response->code($http_exception->getCode());
        }

        if (count($this->httpErrorCallbacks) > 0) {
            foreach (array_reverse($this->httpErrorCallbacks) as $callback) {
                if ($callback instanceof Route) {
                    $this->handleRouteCallback($callback, $matched, $methods_matched);
                } elseif (is_callable($callback)) {
                    if (is_string($callback)) {
                        $callback(
                            $http_exception->getCode(),
                            $this,
                            $matched,
                            $methods_matched,
                            $http_exception
                        );
                    } else {
                        call_user_func(
                            $callback,
                            $http_exception->getCode(),
                            $this,
                            $matched,
                            $methods_matched,
                            $http_exception
                        );
                    }
                }
            }
        }

        // Lock our response, since we probably don't want
        // anything else messing with our error code/body
        $this->response->lock();
    }

    /**
     * Adds a callback to the stack of handlers to run after the dispatch
     * loop has handled all of the route callbacks and before the response
     * is sent
     *
     * @param callable $callback            The callable function to execute in the after route chain
     * @access public
     * @return void
     */
    public function afterDispatch($callback)
    {
        $this->afterFilterCallbacks[] = $callback;
    }

    /**
     * Runs through and executes the after dispatch callbacks
     *
     * @access protected
     * @return void
     */
    protected function callAfterDispatchCallbacks()
    {
        try {
            foreach ($this->afterFilterCallbacks as $callback) {
                if (is_callable($callback)) {
                    if (is_string($callback)) {
                        $callback($this);

                    } else {
                        call_user_func($callback, $this);

                    }
                }
            }
        } catch (Exception $e) {
            $this->error($e);
        }
    }


    /**
     * Method aliases
     */

    /**
     * Quick alias to skip the current callback/route method from executing
     *
     * @throws DispatchHaltedException To halt/skip the current dispatch loop
     * @access public
     * @return void
     */
    public function skipThis()
    {
        throw new DispatchHaltedException(null, DispatchHaltedException::SKIP_THIS);
    }

    /**
     * Quick alias to skip the next callback/route method from executing
     *
     * @param int $num The number of next matches to skip
     * @throws DispatchHaltedException To halt/skip the current dispatch loop
     * @access public
     * @return void
     */
    public function skipNext($num = 1)
    {
        $skip = new DispatchHaltedException(null, DispatchHaltedException::SKIP_NEXT);
        $skip->setNumberOfSkips($num);

        throw $skip;
    }

    /**
     * Quick alias to stop the remaining callbacks/route methods from executing
     *
     * @throws DispatchHaltedException To halt/skip the current dispatch loop
     * @access public
     * @return void
     */
    public function skipRemaining()
    {
        throw new DispatchHaltedException(null, DispatchHaltedException::SKIP_REMAINING);
    }

    /**
     * Alias to set a response code, lock the response, and halt the route matching/dispatching
     *
     * @param int $code     Optional HTTP status code to send
     * @throws DispatchHaltedException To halt/skip the current dispatch loop
     * @access public
     * @return void
     */
    public function abort($code = null)
    {
        if (null !== $code) {
            $this->response->code($code);
        }

        // Disallow further response modification
        $this->response->lock();

        throw new DispatchHaltedException();
    }

    /**
     * OPTIONS alias for "respond()"
     *
     * @see Klein::respond()
     * @param string $route
     * @param callable $callback
     * @access public
     * @return Route
     */
    public function options($path = '*', $callback = null)
    {
        // Options the arguments in a very loose format
        extract(
            $this->parseLooseArgumentOrder(func_get_args()),
            EXTR_OVERWRITE
        );

        return $this->respond('OPTIONS', $path, $callback);
    }

    /**
     * HEAD alias for "respond()"
     *
     * @see Klein::respond()
     * @param string $path
     * @param callable $callback
     * @access public
     * @return Route
     */
    public function head($path = '*', $callback = null)
    {
        // Get the arguments in a very loose format
        extract(
            $this->parseLooseArgumentOrder(func_get_args()),
            EXTR_OVERWRITE
        );

        return $this->respond('HEAD', $path, $callback);
    }

    /**
     * GET alias for "respond()"
     *
     * @see Klein::respond()
     * @param string $route
     * @param callable $callback
     * @access public
     * @return Route
     */
    public function get($path = '*', $callback = null)
    {
        // Get the arguments in a very loose format
        extract(
            $this->parseLooseArgumentOrder(func_get_args()),
            EXTR_OVERWRITE
        );

        return $this->respond('GET', $path, $callback);
    }

    /**
     * POST alias for "respond()"
     *
     * @see Klein::respond()
     * @param string $path
     * @param callable $callback
     * @access public
     * @return Route
     */
    public function post($path = '*', $callback = null)
    {
        // Get the arguments in a very loose format
        extract(
            $this->parseLooseArgumentOrder(func_get_args()),
            EXTR_OVERWRITE
        );

        return $this->respond('POST', $path, $callback);
    }

    /**
     * PUT alias for "respond()"
     *
     * @see Klein::respond()
     * @param string $path
     * @param callable $callback
     * @access public
     * @return Route
     */
    public function put($path = '*', $callback = null)
    {
        // Get the arguments in a very loose format
        extract(
            $this->parseLooseArgumentOrder(func_get_args()),
            EXTR_OVERWRITE
        );

        return $this->respond('PUT', $path, $callback);
    }

    /**
     * DELETE alias for "respond()"
     *
     * @see Klein::respond()
     * @param string $path
     * @param callable $callback
     * @access public
     * @return Route
     */
    public function delete($path = '*', $callback = null)
    {
        // Get the arguments in a very loose format
        extract(
            $this->parseLooseArgumentOrder(func_get_args()),
            EXTR_OVERWRITE
        );

        return $this->respond('DELETE', $path, $callback);
    }

    /**
     * PATCH alias for "respond()"
     *
     * PATCH was added to HTTP/1.1 in RFC5789
     *
     * @link http://tools.ietf.org/html/rfc5789
     * @see Klein::respond()
     * @param string $path
     * @param callable $callback
     * @access public
     * @return Route
     */
    public function patch($path = '*', $callback = null)
    {
        // Get the arguments in a very loose format
        extract(
            $this->parseLooseArgumentOrder(func_get_args()),
            EXTR_OVERWRITE
        );

        return $this->respond('PATCH', $path, $callback);
    }
}
